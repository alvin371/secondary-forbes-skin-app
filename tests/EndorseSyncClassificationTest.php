<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_sync.php';

/**
 * Classification contract for Template::get_social_media responses.
 *
 * Fixtures mirror the real shapes produced by
 * Template::buildTiktokRapidApiFailureResponse(), including the fact that the
 * transport layer stamps every RapidAPI failure with error_class 'transient'.
 */
final class EndorseSyncClassificationTest extends TestCase
{
    private Endorse_sync $sync;

    protected function setUp(): void
    {
        // classify_response only uses static helpers, so the CI-dependent
        // constructor can be skipped.
        $this->sync = (new ReflectionClass(Endorse_sync::class))->newInstanceWithoutConstructor();
    }

    private function classify(array $response): string
    {
        return $this->sync->classify_response($response, 'Tiktok', 'https://www.tiktok.com/@x/photo/1')['class'];
    }

    /** Provider answered at the HTTP layer but refused to resolve the content. */
    private function unresolvable(string $providerMsg, int $http = 200, string $code = '-1'): array
    {
        return [
            'status' => false,
            'msg' => 'tiktok 7670421887255727380 gagal: http=' . $http . ' apicode=' . $code
                . ' apimsg=' . $providerMsg . ' dataid=0',
            'data' => [],
            'error_class' => 'transient',
            'error_meta' => [
                'http_code' => $http,
                'curl_errno' => 0,
                'curl_error' => '',
                'rapidapi_code' => $code,
                'rapidapi_msg' => $providerMsg,
            ],
            'upstream_msg' => '',
        ];
    }

    // ---- 1 & 2: provider URL-resolution failure, incl. case/punctuation variants ----

    public static function unresolvableMessages(): array
    {
        return [
            'production exact'      => ['Url parsing is failed! Please check url.'],
            'lowercase no bang'     => ['url parsing is failed'],
            'uppercase'             => ['URL PARSING IS FAILED!!! PLEASE CHECK URL'],
            'extra whitespace'      => ["  Url   parsing   is   failed !  "],
            'past tense variant'    => ['Url parsing failed, please check url'],
            'content not found'     => ['Content not found'],
            'video not found'       => ['Video not found.'],
        ];
    }

    /** @dataProvider unresolvableMessages */
    public function test_provider_unresolvable_is_data_quality(string $msg): void
    {
        self::assertSame(Endorse_sync::ERR_DATA_QUALITY, $this->classify($this->unresolvable($msg)));
    }

    public function test_data_quality_is_terminal_so_the_cycle_stops_retrying(): void
    {
        self::assertTrue(Endorse_sync::is_terminal_class(Endorse_sync::ERR_DATA_QUALITY));
    }

    // ---- 3: Indonesian permanent messages still map to permanent ----

    public function test_indonesian_permanent_messages_unchanged(): void
    {
        foreach (['Video ID tidak ditemukan dari URL: x', 'URL tidak ditemukan', 'Platform belum tersedia'] as $m) {
            self::assertSame(
                Endorse_sync::ERR_PERMANENT,
                $this->classify(['status' => false, 'msg' => $m, 'data' => []]),
                $m
            );
        }
    }

    public function test_stats_data_tidak_ditemukan_stays_empty(): void
    {
        self::assertSame(
            Endorse_sync::ERR_EMPTY,
            $this->classify(['status' => false, 'msg' => 'Stats data tidak ditemukan', 'data' => []])
        );
    }

    // ---- 4 & 5: infrastructure failures must stay retryable ----

    public function test_infra_stall_stays_infra_stall(): void
    {
        self::assertSame(Endorse_sync::ERR_INFRA_STALL, $this->classify([
            'status' => false,
            'msg' => 'tiktok 1 gagal: http=0 apicode=n/a apimsg=n/a',
            'data' => [],
            'error_class' => Endorse_sync::ERR_INFRA_STALL,
            'error_meta' => ['http_code' => 0, 'curl_errno' => 0, 'rapidapi_code' => 'n/a', 'rapidapi_msg' => 'n/a'],
        ]));
    }

    public function test_timeout_stays_transient(): void
    {
        self::assertSame(Endorse_sync::ERR_TRANSIENT, $this->classify([
            'status' => false,
            'msg' => 'tiktok 1 gagal: http=0 apicode=n/a apimsg=n/a cURL#28=Operation timed out',
            'data' => [],
            'error_class' => Endorse_sync::ERR_TRANSIENT,
            'error_meta' => ['http_code' => 0, 'curl_errno' => 28, 'rapidapi_code' => 'n/a', 'rapidapi_msg' => 'n/a'],
        ]));
    }

    /** False-positive guard: a rate limit must never be suppressed as data quality. */
    public function test_rate_limit_stays_transient_even_with_url_wording(): void
    {
        self::assertSame(
            Endorse_sync::ERR_TRANSIENT,
            $this->classify($this->unresolvable('Url parsing is failed!', 429, '429'))
        );
    }

    /** False-positive guard: an upstream 5xx must never be suppressed. */
    public function test_upstream_5xx_stays_transient(): void
    {
        self::assertSame(
            Endorse_sync::ERR_TRANSIENT,
            $this->classify($this->unresolvable('Url parsing is failed!', 502, '-1'))
        );
    }

    /** False-positive guard: a cURL error with a 200 in stale meta must not match. */
    public function test_curl_error_stays_transient(): void
    {
        $r = $this->unresolvable('Url parsing is failed!');
        $r['error_meta']['curl_errno'] = 7;
        self::assertSame(Endorse_sync::ERR_TRANSIENT, $this->classify($r));
    }

    /**
     * False-positive guard: a temporary provider message that merely mentions a
     * URL must not be read as "this content cannot be resolved".
     * @dataProvider temporaryUrlWordings
     */
    public function test_temporary_message_mentioning_url_stays_transient(string $providerMsg): void
    {
        self::assertSame(
            Endorse_sync::ERR_TRANSIENT,
            $this->classify($this->unresolvable($providerMsg)),
            $providerMsg
        );
    }

    public static function temporaryUrlWordings(): array
    {
        return [
            'busy queue'      => ['Url queue is busy, please retry later'],
            'throttled'       => ['Too many requests for this url, slow down'],
            'internal retry'  => ['Temporary error while fetching url, try again'],
            'maintenance'     => ['Service under maintenance, url processing paused'],
        ];
    }

    // ---- 6, 7, 8: successful payloads must remain OK ----

    public function test_successful_photo_payload_is_ok(): void
    {
        self::assertSame(Endorse_sync::ERR_OK, $this->classify([
            'status' => true,
            'data' => ['content_id' => '7668945525361626375', 'view' => 59419, 'like' => 426,
                       'comment' => 10, 'share' => 3, 'media_type' => 'photo'],
        ]));
    }

    public function test_successful_video_payload_is_ok(): void
    {
        self::assertSame(Endorse_sync::ERR_OK, $this->classify([
            'status' => true,
            'data' => ['content_id' => '7655663980177788180', 'view' => 120345, 'like' => 900,
                       'comment' => 40, 'share' => 12, 'media_type' => 'video'],
        ]));
    }

    public function test_success_with_unchanged_views_is_still_ok(): void
    {
        self::assertSame(Endorse_sync::ERR_OK, $this->classify([
            'status' => true,
            'data' => ['content_id' => '7668945525361626375', 'view' => 59419, 'like' => 426],
        ]));
    }

    // ---- 12: unknown provider error keeps retrying ----

    public function test_unknown_provider_error_stays_transient(): void
    {
        self::assertSame(Endorse_sync::ERR_TRANSIENT, $this->classify([
            'status' => false,
            'msg' => 'tiktok 1 gagal: http=200 apicode=-1 apimsg=Some brand new provider error',
            'data' => [],
            'error_class' => 'transient',
            'error_meta' => ['http_code' => 200, 'curl_errno' => 0,
                             'rapidapi_code' => '-1', 'rapidapi_msg' => 'Some brand new provider error'],
        ]));
    }

    // ---- 9, 10, 11: SUCCESS-INVARIANT GAP — documents CURRENT behaviour ----
    // These assertions record today's behaviour so the follow-up patch has a
    // baseline. They are NOT the desired contract; see the risk analysis.

    public function test_GAP_content_id_without_views_is_currently_accepted_as_ok(): void
    {
        self::assertSame(
            Endorse_sync::ERR_OK,
            $this->classify(['status' => true, 'data' => ['content_id' => '7668945525361626375']]),
            'Documents the success-invariant gap: a payload carrying only a content_id is treated as a valid refresh.'
        );
    }

    public function test_GAP_nonnumeric_views_is_currently_accepted_as_ok(): void
    {
        self::assertSame(
            Endorse_sync::ERR_OK,
            $this->classify(['status' => true, 'data' => ['content_id' => '1', 'view' => 'N/A']]),
            'Documents the success-invariant gap: a non-numeric view value is not rejected.'
        );
    }

    public function test_GAP_decreasing_views_is_not_flagged_at_classification_time(): void
    {
        // Monotonicity is enforced later, in calculate_daily_metrics, not here.
        self::assertSame(
            Endorse_sync::ERR_OK,
            $this->classify(['status' => true, 'data' => ['content_id' => '1', 'view' => 10]])
        );
        $metrics = Endorse_sync::calculate_daily_metrics(
            ['views_after' => 1000], [], ['views' => 10], 1200
        );
        self::assertSame(1200.0, $metrics['after']['views'], 'cumulative must not regress');
        self::assertSame(200.0, $metrics['delta']['views']);
    }
}
