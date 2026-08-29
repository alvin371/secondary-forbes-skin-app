<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Threads_scraper_api.php';

final class ThreadsScraperOutcomeTest extends TestCase
{
    public function test_valid_completed_payload_requires_all_metrics_and_maps_them(): void
    {
        $outcome = Threads_scraper_outcome::poll([
            'status' => 'completed',
            'result' => ['likes' => 295, 'views' => 42100, 'comments' => 175, 'reposts' => 10],
            'error' => null,
        ]);

        self::assertSame('completed', $outcome['kind']);
        self::assertSame(295, $outcome['response']['data']['like']);
        self::assertSame(42100, $outcome['response']['data']['view']);
        self::assertSame(175, $outcome['response']['data']['comment']);
        self::assertSame(10, $outcome['response']['data']['share']);
        self::assertTrue($outcome['response']['stats_validated']);
    }

    public function test_completed_payload_with_result_error_is_never_success(): void
    {
        $outcome = Threads_scraper_outcome::poll([
            'status' => 'completed',
            'result' => [
                'url' => 'https://www.threads.net/@redkamali/post/DcU10JfVif1L',
                'error' => 'Could not parse post data',
                'username' => 'redkamali',
                'post_code' => 'DcU10JfVif1L',
            ],
            'error' => null,
        ]);

        self::assertSame('terminal', $outcome['kind']);
        self::assertSame('provider_parse_error', $outcome['error_code']);
    }

    public function test_missing_or_negative_metric_is_contract_retry_not_zero_stat_success(): void
    {
        foreach ([
            ['likes' => 1, 'views' => 2, 'comments' => 3],
            ['likes' => 1, 'views' => -2, 'comments' => 3, 'reposts' => 4],
        ] as $result) {
            $outcome = Threads_scraper_outcome::poll(['status' => 'completed', 'result' => $result, 'error' => null]);
            self::assertSame('contract_retry', $outcome['kind']);
            self::assertSame('provider_contract_invalid', $outcome['error_code']);
        }
    }

    public function test_zero_metrics_are_valid_when_every_required_field_is_present(): void
    {
        $outcome = Threads_scraper_outcome::poll([
            'status' => 'completed',
            'result' => ['likes' => 0, 'views' => 0, 'comments' => 0, 'reposts' => 0],
        ]);
        self::assertSame('completed', $outcome['kind']);
    }

    public function test_queued_and_running_jobs_remain_waiting(): void
    {
        foreach (['queued', 'pending', 'started', 'running'] as $status) {
            self::assertSame('waiting', Threads_scraper_outcome::poll(['status' => $status])['kind']);
        }
    }

    public function test_top_level_provider_error_is_not_a_success(): void
    {
        $outcome = Threads_scraper_outcome::poll(['status' => 'completed', 'error' => 'Post not found']);
        self::assertSame('terminal', $outcome['kind']);
        self::assertSame('post_not_found', $outcome['error_code']);
    }

    public function test_only_direct_threads_post_urls_are_accepted(): void
    {
        self::assertTrue(Threads_scraper_outcome::isValidPostUrl('https://www.threads.net/@redkamali/post/DcU10JfVif1L'));
        self::assertTrue(Threads_scraper_outcome::isValidPostUrl('https://threads.com/@redkamali/post/DcU10JfVif1L'));
        self::assertFalse(Threads_scraper_outcome::isValidPostUrl('https://www.threads.net/@redkamali'));
        self::assertFalse(Threads_scraper_outcome::isValidPostUrl('https://example.test/@redkamali/post/DcU10JfVif1L'));
    }

    public function test_post_response_job_id_accepts_envelope_and_data_shapes(): void
    {
        self::assertSame('job-a', Threads_scraper_outcome::submitJobId(['job_id' => 'job-a']));
        self::assertSame('job-b', Threads_scraper_outcome::submitJobId(['data' => ['job_id' => 'job-b']]));
        self::assertNull(Threads_scraper_outcome::submitJobId(['status' => 'accepted']));
    }

    public function test_user_messages_are_actionable_and_secret_free(): void
    {
        $message = Threads_scraper_api::userMessage('provider_submit_uncertain');
        self::assertStringContainsString('tidak dapat dipastikan', $message);
        self::assertStringNotContainsString('API', $message);
        self::assertSame('Authorization=<redacted>', Threads_scraper_api::sanitize('Authorization=super-secret'));
    }

    public function test_main_queue_claim_excludes_threads(): void
    {
        $source = file_get_contents(__DIR__ . '/../application/libraries/EndorseRefreshQueueService.php');
        self::assertStringContainsString("status = 'pending' AND platform != 'Threads' AND worker_id IS NULL", $source);
        self::assertStringContainsString("AND platform != 'Threads'", $source);
        self::assertStringContainsString("status = 'failed' AND platform != 'Threads'", $source);
    }

    public function test_threads_route_is_the_explicit_consumer_and_uses_advisory_lock(): void
    {
        $source = file_get_contents(__DIR__ . '/../application/controllers/Api_v2.php');
        self::assertStringContainsString('function cronjob_threads_scraper()', $source);
        self::assertStringContainsString("forbes:cronjob_threads_scraper", $source);
        self::assertStringContainsString('ThreadsEndorseScraperService', $source);
        self::assertStringContainsString("'reason' => 'already_running'", $source);
    }

    public function test_generic_threads_post_stat_path_is_disabled_not_redirected_to_graph(): void
    {
        $source = file_get_contents(__DIR__ . '/../application/libraries/Template.php');
        self::assertStringContainsString("'error_class' => 'threads_queue_required'", $source);
        self::assertStringContainsString('Statistik post Threads diproses melalui antrian scraper Threads.', $source);
        $legacyMethod = substr($source, strpos($source, 'function get_threads_social_media'));
        self::assertStringNotContainsString('graph.threads.net', $legacyMethod);
    }

    public function test_provider_contract_uses_exact_async_endpoints(): void
    {
        $source = file_get_contents(__DIR__ . '/../application/libraries/Threads_scraper_api.php');
        self::assertStringContainsString("'/api/v1/posts/scrape'", $source);
        self::assertStringContainsString("'/api/v1/jobs/' . rawurlencode(\$jobId)", $source);
        self::assertStringContainsString("'X-API-Key: ' . \$this->apiKey", $source);
    }

    public function test_terminal_and_retryable_provider_errors_are_distinguished(): void
    {
        $parse = Threads_scraper_outcome::poll(['status' => 'completed', 'result' => ['error' => 'Could not parse post data']]);
        self::assertSame('terminal', $parse['kind']);
        self::assertSame('provider_parse_error', $parse['error_code']);

        $unknownStatus = Threads_scraper_outcome::poll(['status' => 'finished-with-warning', 'result' => []]);
        self::assertSame('contract_retry', $unknownStatus['kind']);
        self::assertSame('provider_contract_invalid', $unknownStatus['error_code']);
    }
}
