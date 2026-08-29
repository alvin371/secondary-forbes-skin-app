<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sole state-machine owner for Threads rows in endorse_refresh_queue.
 * It never calls Graph API or the generic social-provider fallback.
 */
class ThreadsEndorseScraperService
{
    protected $CI;
    protected $db;
    protected $provider;
    protected $runId;
    protected $now;
    protected $counters;
    protected $touchedCampaigns = [];

    public function __construct(array $config = [])
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('mymodel');
        $this->CI->load->library('Endorse_sync');
        $this->CI->load->library('Threads_scraper_api');
        $this->db = $this->CI->db;
        $this->provider = $config['provider'] ?? $this->CI->threads_scraper_api;
    }

    public function run(int $limit = 10): array
    {
        $this->runId = bin2hex(random_bytes(12));
        $this->now = microtime(true);
        $limit = max(1, min(50, $limit));
        $this->counters = [
            'newly_submitted' => 0, 'polled' => 0, 'still_waiting' => 0,
            'newly_completed' => 0, 'transient_retry_scheduled' => 0,
            'terminal_failed' => 0, 'campaign_rollup_failed' => 0,
            'skipped_already_running' => 0,
        ];

        try {
            $this->recoverExpiredLeases($limit);
            // Poll is bounded and individually isolated; it can never prevent submission.
            $this->pollSubmitted($limit);
            $this->submitPending($limit);
            $this->rollupCommittedCampaigns();
            $snapshot = $this->queueSnapshot();
            $status = ($snapshot['oldest_pending_age_seconds'] ?? 0) > 300 || $this->counters['campaign_rollup_failed'] > 0
                ? 'degraded' : 'ok';
            $result = array_merge(['status' => $status, 'run_id' => $this->runId], $this->counters, ['queue_snapshot' => $snapshot]);
            $this->recordRun($status, $result, null);
            return $result;
        } catch (Throwable $e) {
            $result = array_merge(['status' => 'failed', 'run_id' => $this->runId], $this->counters, [
                'queue_snapshot' => $this->queueSnapshot(),
                'error_code' => 'threads_scraper_fatal',
                'message' => 'Worker Threads gagal dijalankan.',
            ]);
            $this->recordRun('failed', $result, $e);
            log_message('error', 'threads_scraper_fatal run=' . $this->runId . ' type=' . get_class($e));
            return $result;
        }
    }

    protected function submitPending(int $limit): void
    {
        foreach ($this->claimForSubmission($limit) as $row) {
            try {
                $this->submitOne($row);
            } catch (Throwable $e) {
                $this->scheduleRetry($row, 'provider_internal_error', 'Layanan scraper sedang mengalami gangguan. Sistem akan mencoba kembali secara otomatis.');
                log_message('error', 'threads_submit_failed queue=' . intval($row['id']) . ' type=' . get_class($e));
            }
        }
    }

    protected function submitOne(array $row): void
    {
        $queueId = intval($row['id']);
        $url = strval($row['link_upload']);
        if (!Threads_scraper_outcome::isValidPostUrl($url)) {
            $this->terminal($row, 'invalid_threads_url', Threads_scraper_api::userMessage('invalid_threads_url'));
            return;
        }

        $attempt = intval($row['submit_attempts'] ?? $row['attempts'] ?? 0) + 1;
        // This key is persisted before POST.  If (and only if) the provider has
        // documented Idempotency-Key support, a lost response can then be retried
        // with the exact same key instead of creating another provider job.
        $requestKey = $this->idempotencyEnabled() ? $this->requestKey($row) : null;
        $this->db->insert('endorse_refresh_queue_attempts', [
            'queue_id' => $queueId, 'attempt_no' => $attempt, 'worker_id' => $this->runId,
            'status' => 'processing', 'started_at' => $this->dbNow(), 'created_at' => $this->dbNow(),
        ]);
        $this->db->update('endorse_refresh_queue', [
            'submit_attempts' => $attempt, 'attempts' => $attempt, 'attempt_sequence' => $attempt,
            'active_attempt_id' => intval($this->db->insert_id()), 'provider_request_key' => $requestKey,
        ], ['id' => $queueId, 'status' => 'processing', 'worker_id' => $this->runId]);

        // Idempotency-Key stays disabled unless the external provider contract confirms it.
        $response = $this->provider->submitPost($url, $requestKey);
        if (!empty($response['ok'])) {
            $jobId = Threads_scraper_outcome::submitJobId($response['data']);
            if ($jobId !== null) {
                $this->transitionSubmitted($row, $attempt, $jobId);
                return;
            }
            $this->scheduleRetry($row, 'provider_contract_invalid', Threads_scraper_api::userMessage('provider_contract_invalid'), $attempt, true);
            return;
        }

        // A lost POST response may hide an accepted job. Without a verified idempotency
        // contract it is safer to stop than create duplicate provider jobs.
        if (!empty($response['ambiguous'])) {
            if (!$this->idempotencyEnabled()) {
                $this->terminal($row, 'provider_submit_uncertain', Threads_scraper_api::userMessage('provider_submit_uncertain'), $attempt, $response['diagnostic'] ?? '');
                return;
            }
            $this->scheduleRetry($row, strval($response['error_code'] ?? 'provider_timeout'), strval($response['message'] ?? Threads_scraper_api::userMessage('provider_timeout')), $attempt, false, $response['retry_after'] ?? null, $response['diagnostic'] ?? '');
            return;
        }
        if ($this->isTransientCode(strval($response['error_code'] ?? ''))) {
            $this->scheduleRetry($row, strval($response['error_code']), strval($response['message']), $attempt, false, $response['retry_after'] ?? null, $response['diagnostic'] ?? '');
            return;
        }
        $this->terminal($row, strval($response['error_code'] ?? 'provider_request_rejected'), strval($response['message'] ?? 'Scraping Threads gagal.'), $attempt, $response['diagnostic'] ?? '');
    }

    protected function pollSubmitted(int $limit): void
    {
        foreach ($this->claimForPoll($limit) as $row) {
            try {
                $this->pollOne($row);
            } catch (Throwable $e) {
                $this->returnToPoll($row, 'provider_internal_error', 'Layanan scraper sedang mengalami gangguan. Sistem akan mencoba kembali secara otomatis.');
                log_message('error', 'threads_poll_failed queue=' . intval($row['id']) . ' type=' . get_class($e));
            }
        }
    }

    protected function pollOne(array $row): void
    {
        $this->counters['polled']++;
        if ($this->isPastDeadline($row)) {
            $this->terminal($row, 'provider_timeout', Threads_scraper_api::userMessage('provider_timeout'), null, 'provider_processing_deadline_exceeded');
            return;
        }
        $jobId = trim(strval($row['provider_job_id'] ?? ''));
        if ($jobId === '') {
            $this->terminal($row, 'provider_contract_invalid', Threads_scraper_api::userMessage('provider_contract_invalid'));
            return;
        }
        $response = $this->provider->getJob($jobId);
        if (empty($response['ok'])) {
            if ($this->isTransientCode(strval($response['error_code'] ?? ''))) {
                $this->returnToPoll($row, strval($response['error_code']), strval($response['message']), $response['retry_after'] ?? null, $response['diagnostic'] ?? '');
            } else {
                $this->terminal($row, strval($response['error_code'] ?? 'provider_http_error'), strval($response['message'] ?? 'Scraping Threads gagal.'), null, $response['diagnostic'] ?? '');
            }
            return;
        }

        $outcome = Threads_scraper_outcome::poll($response['data']);
        if ($outcome['kind'] === 'waiting') {
            $this->returnToPoll($row, null, null);
            $this->counters['still_waiting']++;
            return;
        }
        if ($outcome['kind'] === 'terminal') {
            $this->terminal($row, $outcome['error_code'], $outcome['message']);
            return;
        }
        if ($outcome['kind'] === 'contract_retry') {
            $contracts = intval($row['transient_failures'] ?? 0) + 1;
            if ($contracts > $this->contractRetryLimit()) {
                $this->terminal($row, 'provider_contract_invalid', 'Scraping gagal setelah beberapa percobaan. Silakan coba lagi nanti.');
            } else {
                $this->returnToPoll($row, $outcome['error_code'], $outcome['message'], null, null, $contracts);
            }
            return;
        }
        $this->complete($row, $outcome['response']);
    }

    protected function complete(array $row, array $response): void
    {
        $queueId = intval($row['id']);
        $jobId = strval($row['provider_job_id']);
        $this->db->trans_begin();
        try {
            $current = $this->CI->mymodel->selectWithQuery("SELECT * FROM endorse_refresh_queue WHERE id = $queueId AND status = 'submitted' AND provider_job_id = " . $this->db->escape($jobId) . " FOR UPDATE");
            if (empty($current)) {
                $this->db->trans_rollback();
                return; // A replay after successful completion is idempotent.
            }
            $endorseRows = $this->CI->mymodel->selectWithQuery('SELECT * FROM endorse WHERE id = ' . intval($row['id_endorse']) . ' LIMIT 1');
            if (empty($endorseRows)) {
                throw new RuntimeException('endorse_missing');
            }
            $response['queue_id'] = $queueId;
            $response['data']['content_id'] = self::contentKey(strval($row['link_upload']));
            $purpose = strval($row['purpose'] ?? 'daily');
            $applied = $purpose === 'daily'
                ? $this->CI->endorse_sync->apply($endorseRows[0], $response, intval($row['enqueued_by'] ?? 0))
                : $this->CI->endorse_sync->apply_snapshot($endorseRows[0], $response, $purpose, intval($row['enqueued_by'] ?? 0));
            if (empty($applied['status'])) {
                throw new RuntimeException('stats_apply_failed:' . strval($applied['error_class'] ?? 'unknown'));
            }
            $hash = hash('sha256', $jobId . '|' . json_encode($response['data']));
            $this->db->insert('threads_scraper_results', [
                'queue_id' => $queueId, 'provider_job_id' => $jobId, 'result_hash' => $hash,
                'applied_at' => $this->dbNow(), 'created_at' => $this->dbNow(),
            ]);
            $this->db->update('endorse_refresh_queue', [
                'status' => 'completed', 'worker_id' => null, 'lease_expires_at' => null,
                'active_attempt_id' => null, 'error_code' => null, 'error_message' => null,
                'user_error_message' => null, 'completed_at' => $this->dbNow(),
            ], ['id' => $queueId, 'status' => 'submitted', 'provider_job_id' => $jobId]);
            $this->db->trans_commit();
            if ($purpose === 'daily') {
                $this->touchedCampaigns[intval($endorseRows[0]['id_campaign'])] = true;
            }
            $this->counters['newly_completed']++;
        } catch (Throwable $e) {
            $this->db->trans_rollback();
            $this->returnToPoll($row, 'stats_apply_failed', 'Data statistik belum dapat disimpan. Sistem akan mencoba kembali secara otomatis.');
        }
    }

    protected function transitionSubmitted(array $row, int $attempt, string $jobId): void
    {
        $now = $this->dbNow();
        $this->db->update('endorse_refresh_queue', [
            'status' => 'submitted', 'provider_job_id' => $jobId, 'provider_request_key' => $this->idempotencyEnabled() ? $this->requestKey($row) : null,
            'submitted_at' => $now, 'provider_submitted_at' => $now, 'next_poll_at' => $this->dbFuture($this->graceSeconds()),
            'provider_processing_deadline_at' => $this->dbFuture($this->maxProcessingAge()),
            'worker_id' => null, 'lease_expires_at' => null, 'error_code' => null, 'error_message' => null,
            'user_error_message' => null,
        ], ['id' => intval($row['id']), 'status' => 'processing', 'worker_id' => $this->runId]);
        $this->finishSubmitAttempt($row, $attempt, 'submitted', null, null);
        $this->counters['newly_submitted']++;
    }

    protected function returnToPoll(array $row, ?string $code, ?string $message, ?int $retryAfter = null, ?string $diagnostic = null, ?int $failures = null): void
    {
        $failures = $failures ?? (intval($row['transient_failures'] ?? 0) + ($code === null ? 0 : 1));
        $delay = $retryAfter ?? min($this->pollMaxBackoff(), $this->pollBackoff() * (2 ** min(6, max(0, $failures - 1))));
        $this->db->update('endorse_refresh_queue', [
            'status' => 'submitted', 'worker_id' => null, 'lease_expires_at' => null,
            'poll_attempts' => intval($row['poll_attempts'] ?? 0) + 1,
            'transient_failures' => $failures, 'next_poll_at' => $this->dbFuture($delay),
            'error_code' => $code, 'error_message' => Threads_scraper_api::sanitize(strval($diagnostic ?? '')),
            'user_error_message' => $message,
        ], ['id' => intval($row['id']), 'status' => 'submitted', 'worker_id' => $this->runId]);
        if ($code !== null) $this->counters['transient_retry_scheduled']++;
    }

    protected function scheduleRetry(array $row, string $code, string $message, ?int $attempt = null, bool $contract = false, ?int $retryAfter = null, ?string $diagnostic = null): void
    {
        $attempt = $attempt ?? intval($row['submit_attempts'] ?? $row['attempts'] ?? 0);
        $max = max(1, intval($row['max_attempts'] ?? env('SOCIAL_SCRAPER_MAX_SUBMIT_ATTEMPTS', 3)));
        if ($attempt >= $max || ($contract && intval($row['transient_failures'] ?? 0) + 1 > $this->contractRetryLimit())) {
            $this->terminal($row, 'max_attempts_exceeded', Threads_scraper_api::userMessage('max_attempts_exceeded'), $attempt, $diagnostic);
            return;
        }
        $delay = $retryAfter ?? min($this->retryMaxBackoff(), $this->retryBackoff() * (2 ** min(6, max(0, $attempt - 1))));
        $this->db->update('endorse_refresh_queue', [
            'status' => 'retrying', 'provider_job_id' => null, 'worker_id' => null, 'lease_expires_at' => null,
            'active_attempt_id' => null, 'transient_failures' => intval($row['transient_failures'] ?? 0) + 1,
            'next_retry_at' => $this->dbFuture($delay), 'error_code' => $code,
            'error_message' => Threads_scraper_api::sanitize(strval($diagnostic ?? '')),
            'user_error_message' => $message,
        ], ['id' => intval($row['id']), 'status' => 'processing', 'worker_id' => $this->runId]);
        $this->finishSubmitAttempt($row, $attempt, 'retrying', $code, $message);
        $this->counters['transient_retry_scheduled']++;
    }

    protected function terminal(array $row, string $code, string $message, ?int $attempt = null, string $diagnostic = ''): void
    {
        $where = ['id' => intval($row['id'])];
        if (!empty($row['worker_id'])) $where['worker_id'] = $this->runId;
        $this->db->update('endorse_refresh_queue', [
            'status' => 'failed', 'worker_id' => null, 'lease_expires_at' => null, 'active_attempt_id' => null,
            'next_poll_at' => null, 'next_retry_at' => null, 'error_code' => $code,
            'error_message' => Threads_scraper_api::sanitize($diagnostic), 'user_error_message' => $message,
            'completed_at' => $this->dbNow(),
        ], $where);
        if ($attempt !== null) $this->finishSubmitAttempt($row, $attempt, 'failed', $code, $message);
        $this->counters['terminal_failed']++;
    }

    protected function finishSubmitAttempt(array $row, int $attempt, string $status, ?string $code, ?string $message): void
    {
        $data = ['status' => $status, 'error_class' => $this->legacyErrorClass($code), 'error_message' => $message, 'finished_at' => $this->dbNow()];
        if ($this->db->field_exists('error_code', 'endorse_refresh_queue_attempts')) $data['error_code'] = $code;
        $this->db->update('endorse_refresh_queue_attempts', $data, ['queue_id' => intval($row['id']), 'attempt_no' => $attempt, 'worker_id' => $this->runId]);
    }

    protected function claimForSubmission(int $limit): array
    {
        $lease = $this->sqlFuture($this->leaseSeconds());
        $this->db->query("UPDATE endorse_refresh_queue SET status = 'processing', worker_id = ?, claimed_at = NOW(), started_at = NOW(), lease_expires_at = $lease WHERE platform = 'Threads' AND worker_id IS NULL AND ((status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= NOW())) OR (status = 'retrying' AND next_retry_at <= NOW())) ORDER BY priority DESC, submit_attempts ASC, created_at ASC LIMIT $limit", [$this->runId]);
        return $this->CI->mymodel->selectWithQuery("SELECT * FROM endorse_refresh_queue WHERE status = 'processing' AND worker_id = " . $this->db->escape($this->runId)) ?: [];
    }

    protected function claimForPoll(int $limit): array
    {
        $lease = $this->sqlFuture($this->leaseSeconds());
        $this->db->query("UPDATE endorse_refresh_queue SET worker_id = ?, lease_expires_at = $lease WHERE platform = 'Threads' AND status = 'submitted' AND worker_id IS NULL AND next_poll_at <= NOW() ORDER BY next_poll_at ASC, id ASC LIMIT $limit", [$this->runId]);
        return $this->CI->mymodel->selectWithQuery("SELECT * FROM endorse_refresh_queue WHERE status = 'submitted' AND worker_id = " . $this->db->escape($this->runId)) ?: [];
    }

    protected function recoverExpiredLeases(int $limit): void
    {
        $this->db->query("UPDATE endorse_refresh_queue SET status = CASE WHEN provider_job_id IS NULL OR provider_job_id = '' THEN 'pending' ELSE 'submitted' END, worker_id = NULL, lease_expires_at = NULL, next_retry_at = CASE WHEN provider_job_id IS NULL OR provider_job_id = '' THEN NOW() ELSE next_retry_at END, next_poll_at = CASE WHEN provider_job_id IS NULL OR provider_job_id = '' THEN next_poll_at ELSE NOW() END, error_code = 'lease_expired', user_error_message = 'Proses sebelumnya terhenti; item dijadwalkan kembali.' WHERE platform = 'Threads' AND worker_id IS NOT NULL AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW() LIMIT $limit");
        // Repair incomplete non-terminal state from an interrupted older build;
        // each row must retain a deterministic future/now action timestamp.
        $this->db->query("UPDATE endorse_refresh_queue SET next_poll_at = NOW(), error_code = 'schedule_repaired', user_error_message = 'Jadwal polling diperbaiki.' WHERE platform = 'Threads' AND status = 'submitted' AND provider_job_id IS NOT NULL AND provider_job_id != '' AND next_poll_at IS NULL LIMIT $limit");
        $this->db->query("UPDATE endorse_refresh_queue SET next_retry_at = NOW(), error_code = 'schedule_repaired', user_error_message = 'Jadwal retry diperbaiki.' WHERE platform = 'Threads' AND status = 'retrying' AND next_retry_at IS NULL LIMIT $limit");
    }

    protected function rollupCommittedCampaigns(): void
    {
        foreach (array_keys($this->touchedCampaigns) as $campaignId) {
            try {
                $this->CI->endorse_sync->update_campaign_parent(intval($campaignId), 0);
            } catch (Throwable $e) {
                $this->counters['campaign_rollup_failed']++;
                log_message('error', 'threads_campaign_rollup_failed campaign=' . intval($campaignId) . ' type=' . get_class($e));
            }
        }
    }

    protected function queueSnapshot(): array
    {
        $rows = $this->CI->mymodel->selectWithQuery("SELECT status, COUNT(*) AS c, MIN(created_at) AS oldest_created, MIN(submitted_at) AS oldest_submitted FROM endorse_refresh_queue WHERE platform = 'Threads' GROUP BY status") ?: [];
        $result = ['pending_total' => 0, 'submitted_total' => 0, 'retrying_total' => 0, 'failed_total' => 0, 'oldest_pending_age_seconds' => null, 'oldest_submitted_age_seconds' => null];
        foreach ($rows as $row) {
            $key = strval($row['status']) . '_total';
            if (array_key_exists($key, $result)) $result[$key] = intval($row['c']);
            if ($row['status'] === 'pending' || $row['status'] === 'retrying') $result['oldest_pending_age_seconds'] = $this->ageSeconds($row['oldest_created']);
            if ($row['status'] === 'submitted') $result['oldest_submitted_age_seconds'] = $this->ageSeconds($row['oldest_submitted']);
        }
        return $result;
    }

    protected function recordRun(string $status, array $result, ?Throwable $error): void
    {
        if (!$this->db->table_exists('threads_scraper_runs')) return;
        try {
            $this->db->insert('threads_scraper_runs', [
                'run_id' => $this->runId, 'status' => $status,
                'started_at' => gmdate('Y-m-d H:i:s', intval($this->now)), 'finished_at' => gmdate('Y-m-d H:i:s'),
                'duration_ms' => intval((microtime(true) - $this->now) * 1000),
                'counters_json' => json_encode($this->counters), 'snapshot_json' => json_encode($result['queue_snapshot'] ?? []),
                'fatal_error_code' => $error ? 'threads_scraper_fatal' : null,
                'fatal_error_message' => $error ? get_class($error) : null, 'created_at' => $this->dbNow(),
            ]);
        } catch (Throwable $ignored) {
            log_message('error', 'threads_scraper_run_record_failed');
        }
    }

    protected function isTransientCode(string $code): bool { return in_array($code, ['provider_timeout', 'provider_network_error', 'provider_connection_error', 'provider_rate_limited', 'provider_server_error', 'provider_contract_invalid', 'provider_internal_error', 'stats_apply_failed'], true); }
    protected function isPastDeadline(array $row): bool { $deadline = strval($row['provider_processing_deadline_at'] ?? ''); return $deadline !== '' && strtotime($deadline) <= time(); }
    protected function graceSeconds(): int { return max(30, min(600, intval(env('SOCIAL_SCRAPER_GRACE_SEC', 60)))); }
    protected function maxProcessingAge(): int { return max($this->graceSeconds(), min(86400, intval(env('SOCIAL_SCRAPER_JOB_TIMEOUT_SEC', 900)))); }
    protected function leaseSeconds(): int { return max(60, min(900, intval(env('SOCIAL_SCRAPER_LEASE_SEC', 120)))); }
    protected function pollBackoff(): int { return max(15, min(600, intval(env('SOCIAL_SCRAPER_POLL_BACKOFF_SEC', 60)))); }
    protected function pollMaxBackoff(): int { return max($this->pollBackoff(), min(3600, intval(env('SOCIAL_SCRAPER_POLL_MAX_BACKOFF_SEC', 300)))); }
    protected function retryBackoff(): int { return max(15, min(600, intval(env('SOCIAL_SCRAPER_RETRY_BACKOFF_SEC', 60)))); }
    protected function retryMaxBackoff(): int { return max($this->retryBackoff(), min(3600, intval(env('SOCIAL_SCRAPER_RETRY_MAX_BACKOFF_SEC', 300)))); }
    protected function contractRetryLimit(): int { return max(0, min(5, intval(env('SOCIAL_SCRAPER_CONTRACT_RETRY_LIMIT', 2)))); }
    protected function idempotencyEnabled(): bool { return env('SOCIAL_SCRAPER_IDEMPOTENCY_SUPPORTED', '0') === '1'; }
    protected function requestKey(array $row): string { return 'threads-q' . intval($row['id']); }
    protected function legacyErrorClass(?string $code): ?string
    {
        if ($code === null) return null;
        if (in_array($code, ['provider_timeout', 'provider_network_error', 'provider_connection_error', 'provider_rate_limited', 'provider_server_error', 'provider_internal_error', 'stats_apply_failed'], true)) return 'transient';
        if ($code === 'provider_submit_uncertain') return 'uncertain';
        if ($code === 'provider_contract_invalid') return 'contract';
        return 'permanent';
    }
    protected function dbNow(): string { return date('Y-m-d H:i:s'); }
    protected function dbFuture(int $seconds): string { return date('Y-m-d H:i:s', time() + max(1, intval($seconds))); }
    protected function sqlFuture(int $seconds): string { return 'DATE_ADD(NOW(), INTERVAL ' . max(1, intval($seconds)) . ' SECOND)'; }
    protected function ageSeconds($value): ?int { $time = $value ? strtotime((string) $value) : false; return $time === false ? null : max(0, time() - $time); }
    protected static function contentKey(string $url): string { $path = trim((string) parse_url($url, PHP_URL_PATH), '/'); return $path === '' ? hash('sha256', $url) : substr(str_replace('/', ':', $path), -191); }
}
