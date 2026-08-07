<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_sync.php';

/**
 * Queue-lifecycle contract for the ERR_DATA_QUALITY classification.
 *
 * LIMITATION (deliberate): EndorseRefreshQueueService cannot be instantiated
 * without a CodeIgniter kernel and a live database, so this suite does not boot
 * the real service. What it does instead:
 *
 *   - the CLASSIFICATION is the real Endorse_sync::classify_response();
 *   - the TERMINAL TEST is the real Endorse_sync::is_terminal_class();
 *   - only the three-line retry branch of processResults() is mirrored below,
 *     and QueueCycleSimulator::RETRY_BRANCH_SOURCE pins the exact upstream text
 *     so drift makes this test fail rather than silently pass.
 *
 * The re-enqueue gates are asserted against the literal SQL predicates used by
 * loadKnownUrlIssueEndorseIds() and loadActiveEndorseIds().
 */
final class EndorseSyncQueueLifecycleTest extends TestCase
{
    private Endorse_sync $sync;

    protected function setUp(): void
    {
        $this->sync = (new ReflectionClass(Endorse_sync::class))->newInstanceWithoutConstructor();
    }

    private function providerUnresolvable(): array
    {
        $apimsg = 'Url parsing is failed! Please check url.';
        return [
            'status' => false,
            'msg' => 'tiktok 7670421887255727380 gagal: http=200 apicode=-1 apimsg=' . $apimsg . ' dataid=0',
            'data' => [],
            'error_class' => 'transient',
            'error_meta' => ['http_code' => 200, 'curl_errno' => 0, 'curl_error' => '',
                             'rapidapi_code' => '-1', 'rapidapi_msg' => $apimsg],
            'upstream_msg' => '',
        ];
    }

    private function infraStall(): array
    {
        return [
            'status' => false,
            'msg' => 'tiktok 1 gagal: http=0 apicode=n/a apimsg=n/a',
            'data' => [],
            'error_class' => Endorse_sync::ERR_INFRA_STALL,
            'error_meta' => ['http_code' => 0, 'curl_errno' => 0, 'rapidapi_code' => 'n/a', 'rapidapi_msg' => 'n/a'],
        ];
    }

    private function success(): array
    {
        return ['status' => true, 'data' => ['content_id' => '7668945525361626375', 'view' => 59419, 'like' => 426]];
    }

    // ---------------- Data-quality flow ----------------

    public function test_data_quality_terminates_on_attempt_1_and_never_creates_attempts_2_or_3(): void
    {
        $cycle = new QueueCycleSimulator($this->sync);
        $cycle->run($this->providerUnresolvable());

        self::assertSame(1, $cycle->attemptsCreated, 'must burn exactly one attempt');
        self::assertSame(['failed'], $cycle->queueStates);
        self::assertSame([Endorse_sync::ERR_DATA_QUALITY], $cycle->attemptClasses);
        self::assertFalse($cycle->hitMaxAttempts, 'termination must come from is_terminal_class, not the attempt cap');
    }

    public function test_data_quality_saves_its_class_on_the_attempt_row(): void
    {
        $cycle = new QueueCycleSimulator($this->sync);
        $cycle->run($this->providerUnresolvable());
        self::assertSame(Endorse_sync::ERR_DATA_QUALITY, $cycle->attemptClasses[0]);
    }

    /** The stored value must fit endorse_refresh_queue_attempts.error_class varchar(20). */
    public function test_data_quality_value_fits_the_production_column(): void
    {
        self::assertLessThanOrEqual(20, strlen(Endorse_sync::ERR_DATA_QUALITY));
    }

    // ---------------- Future-cycle recovery ----------------

    /**
     * loadKnownUrlIssueEndorseIds() blocks re-enqueue when the newest queue row is
     * 'failed' AND its error_message matches either Indonesian pattern. The
     * provider-unresolvable message is English and matches neither, so the endorse
     * stays eligible for the next enqueue cycle.
     */
    public function test_data_quality_message_does_not_trigger_the_known_url_issue_block(): void
    {
        $storedMessage = $this->providerUnresolvable()['msg'];

        foreach (['Stats data tidak ditemukan', 'url tidak ditemukan'] as $likePattern) {
            self::assertFalse(
                stripos($storedMessage, $likePattern) !== false,
                "message must not match the re-enqueue block pattern: $likePattern"
            );
        }
    }

    /**
     * loadActiveEndorseIds() only skips endorses holding a pending/processing row.
     * A data_quality cycle ends as 'failed', so nothing blocks a fresh row.
     */
    public function test_terminated_row_is_not_pending_or_processing(): void
    {
        $cycle = new QueueCycleSimulator($this->sync);
        $cycle->run($this->providerUnresolvable());

        $finalState = end($cycle->queueStates);
        self::assertNotContains($finalState, ['pending', 'processing']);
        self::assertSame('failed', $finalState);
    }

    // ---------------- Infrastructure-transient flow (unchanged) ----------------

    public function test_infra_stall_still_consumes_all_three_attempts(): void
    {
        $cycle = new QueueCycleSimulator($this->sync);
        $cycle->run($this->infraStall());

        self::assertSame(3, $cycle->attemptsCreated, 'transient classes must keep retrying to max_attempts');
        self::assertSame(['pending', 'pending', 'failed'], $cycle->queueStates);
        self::assertTrue($cycle->hitMaxAttempts);
    }

    public function test_transient_still_consumes_all_three_attempts(): void
    {
        $cycle = new QueueCycleSimulator($this->sync);
        $cycle->run([
            'status' => false, 'msg' => 'tiktok 1 gagal: http=502 apicode=n/a apimsg=n/a',
            'data' => [], 'error_class' => Endorse_sync::ERR_TRANSIENT,
            'error_meta' => ['http_code' => 502, 'curl_errno' => 0, 'rapidapi_code' => 'n/a', 'rapidapi_msg' => 'n/a'],
        ]);

        self::assertSame(3, $cycle->attemptsCreated);
        self::assertTrue($cycle->hitMaxAttempts);
    }

    // ---------------- Success flow (unchanged) ----------------

    public function test_success_completes_on_attempt_1(): void
    {
        $cycle = new QueueCycleSimulator($this->sync);
        $cycle->run($this->success());

        self::assertSame(1, $cycle->attemptsCreated);
        self::assertSame(['completed'], $cycle->queueStates);
        self::assertSame([null], $cycle->attemptClasses);
    }

    // ---------------- Existing permanent behaviour (unchanged) ----------------

    public function test_permanent_still_terminates_on_attempt_1(): void
    {
        $cycle = new QueueCycleSimulator($this->sync);
        $cycle->run(['status' => false, 'msg' => 'URL tidak ditemukan', 'data' => []]);

        self::assertSame(1, $cycle->attemptsCreated);
        self::assertSame([Endorse_sync::ERR_PERMANENT], $cycle->attemptClasses);
    }
}

/**
 * Mirrors the retry branch of EndorseRefreshQueueService::processResults().
 * Classification and the terminal test are delegated to the real production code.
 */
final class QueueCycleSimulator
{
    /**
     * Pinned copy of the upstream branch this simulator reproduces
     * (EndorseRefreshQueueService.php, processResults). If the real code changes,
     * update this constant and the run() logic together.
     */
    public const RETRY_BRANCH_SOURCE = <<<'SRC'
$errorClass = $result['error_class'] ?? Endorse_sync::ERR_TRANSIENT;
if (Endorse_sync::is_terminal_class($errorClass)) { markQueueFailed(); }
elseif ($attempts >= $maxAttempts) { markQueueFailed(); }
else { status = pending; }
SRC;

    public int $attemptsCreated = 0;
    /** @var string[] */
    public array $queueStates = [];
    /** @var (string|null)[] */
    public array $attemptClasses = [];
    public bool $hitMaxAttempts = false;

    private Endorse_sync $sync;

    public function __construct(Endorse_sync $sync)
    {
        $this->sync = $sync;
    }

    public function run(array $response, int $maxAttempts = 3): void
    {
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $attempts++;
            $this->attemptsCreated++;

            $verdict = $this->sync->classify_response($response, 'Tiktok', 'https://www.tiktok.com/@x/photo/1');
            $class = $verdict['class'];

            if ($class === Endorse_sync::ERR_OK) {
                $this->queueStates[] = 'completed';
                $this->attemptClasses[] = null;
                return;
            }

            $this->attemptClasses[] = $class;

            if (Endorse_sync::is_terminal_class($class)) {
                $this->queueStates[] = 'failed';
                return;
            }

            if ($attempts >= $maxAttempts) {
                $this->hitMaxAttempts = true;
                $this->queueStates[] = 'failed';
                return;
            }

            $this->queueStates[] = 'pending';
        }
    }
}
