<?php
defined('BASEPATH') or exit('No direct script access allowed');

class EndorseRefreshQueueService
{
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->model('mymodel');
        $this->CI->load->library('endorse_sync');
    }

    public function enqueueCampaign($idCampaign, $userId = 0, $targetIds = [])
    {
        $idCampaign = intval($idCampaign);
        $userId = intval($userId);
        $targetIds = array_values(array_unique(array_filter(array_map('intval', (array) $targetIds))));

        if ($idCampaign <= 0) {
            return [
                'status' => false,
                'msg' => 'Campaign tidak valid.',
                'enqueued' => 0,
                'skipped_duplicates' => 0,
                'excluded_known_url' => 0,
            ];
        }

        $extraFilter = '';
        if (!empty($targetIds)) {
            $extraFilter = ' AND id IN (' . implode(',', $targetIds) . ')';
        }

        $rows = $this->CI->mymodel->selectWithQuery("SELECT id, id_campaign, platform, link_upload
            FROM endorse
            WHERE id_campaign = '{$idCampaign}'
              AND status = 'Aktif'
              AND status_campaign = 'Aktif'
              AND link_upload != ''
              {$extraFilter}
            ORDER BY id ASC");

        $enqueued = 0;
        $skippedDuplicates = 0;
        $excludedKnownUrl = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $idEndorse = intval($row['id'] ?? 0);
            if ($idEndorse <= 0) {
                continue;
            }

            $active = $this->CI->mymodel->selectWithQuery("SELECT id
                FROM endorse_refresh_queue
                WHERE id_endorse = '{$idEndorse}'
                  AND status IN ('pending', 'processing')
                LIMIT 1");
            if (!empty($active)) {
                $skippedDuplicates++;
                continue;
            }

            $latestFailure = $this->CI->mymodel->selectWithQuery("SELECT error_message
                FROM endorse_refresh_queue
                WHERE id_endorse = '{$idEndorse}' AND status = 'failed'
                ORDER BY id DESC
                LIMIT 1");
            $latestFailure = $latestFailure[0]['error_message'] ?? '';
            if ($this->CI->endorse_sync->isKnownPermanentFailure($latestFailure)) {
                $excludedKnownUrl++;
                continue;
            }

            $payload = [
                'id_endorse' => $idEndorse,
                'id_campaign' => intval($row['id_campaign'] ?? $idCampaign),
                'platform' => strval($row['platform'] ?? ''),
                'link_upload' => strval($row['link_upload'] ?? ''),
                'status' => 'pending',
                'priority' => 10,
                'attempts' => 0,
                'max_attempts' => 3,
                'error_message' => null,
                'claimed_at' => null,
                'worker_id' => null,
                'enqueued_by' => $userId > 0 ? $userId : null,
                'retry_source_id' => null,
                'created_at' => $now,
                'started_at' => null,
                'completed_at' => null,
            ];
            $this->CI->db->insert('endorse_refresh_queue', $payload);
            $enqueued++;
        }

        return [
            'status' => true,
            'msg' => $enqueued . ' konten ditambahkan ke antrian.',
            'enqueued' => $enqueued,
            'skipped_duplicates' => $skippedDuplicates,
            'excluded_known_url' => $excludedKnownUrl,
            'id_campaign' => $idCampaign,
        ];
    }

    public function computeHealth()
    {
        $summaryRows = $this->CI->mymodel->selectWithQuery("SELECT status, COUNT(*) AS total
            FROM endorse_refresh_queue
            GROUP BY status");

        $summary = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
        foreach ($summaryRows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if (array_key_exists($status, $summary)) {
                $summary[$status] = intval($row['total'] ?? 0);
            }
        }

        $timestamps = $this->CI->mymodel->selectWithQuery("SELECT
            MIN(CASE WHEN status = 'pending' THEN created_at END) AS oldest_pending_at,
            MAX(CASE WHEN status = 'completed' THEN completed_at END) AS last_completed_at,
            MAX(started_at) AS last_started_at
            FROM endorse_refresh_queue");
        $timestamps = $timestamps[0] ?? [];

        $processing = intval($summary['processing']);
        $pending = intval($summary['pending']);
        $staleMinutes = intval(env('ENDORSE_QUEUE_STALE_MINUTES', 10));
        if ($staleMinutes < 5) {
            $staleMinutes = 10;
        }

        $activityCandidates = array_filter([
            $timestamps['last_started_at'] ?? null,
            $timestamps['last_completed_at'] ?? null,
            $timestamps['oldest_pending_at'] ?? null,
        ]);
        $lastActivityAt = null;
        foreach ($activityCandidates as $candidate) {
            if ($lastActivityAt === null || strtotime($candidate) > strtotime($lastActivityAt)) {
                $lastActivityAt = $candidate;
            }
        }
        $isStalled = false;
        if ($pending > 0 && $processing === 0 && !empty($lastActivityAt)) {
            $isStalled = (time() - strtotime($lastActivityAt)) > ($staleMinutes * 60);
        }

        return [
            'summary' => $summary,
            'health' => [
                'active_total' => $pending + $processing,
                'pending_total' => $pending,
                'processing_total' => $processing,
                'oldest_pending_at' => $timestamps['oldest_pending_at'] ?: null,
                'last_completed_at' => $timestamps['last_completed_at'] ?: null,
                'last_started_at' => $timestamps['last_started_at'] ?: null,
                'is_stalled' => $isStalled,
            ],
        ];
    }
}
