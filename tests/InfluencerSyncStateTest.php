<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/helpers/social_platform_helper.php';
require_once __DIR__ . '/../application/libraries/Template.php';
require_once __DIR__ . '/../application/libraries/Endorse_sync.php';

/**
 * Influencer URL normalization (network steps stubbed with redirects seen live on
 * 2026-10-16), the sync state policy, and the hidden-like carry.
 */
final class InfluencerSyncStateTest extends TestCase
{
    /** Template whose network calls return canned answers and are recorded. */
    private function template(array $redirects = [], array $owners = [], array $instagram = []): Template
    {
        return new class ($redirects, $owners, $instagram) extends Template {
            public array $calls = [];
            private array $redirects;
            private array $owners;
            private array $instagram;
            public function __construct(array $redirects, array $owners, array $instagram)
            {
                $this->redirects = $redirects;
                $this->owners = $owners;
                $this->instagram = $instagram;
            }
            protected function resolve_social_redirect($url)
            {
                $this->calls[] = "resolve $url";
                return $this->redirects[$url] ?? '';
            }
            protected function lookup_social_post_owner($type, $url)
            {
                $this->calls[] = "owner $url";
                return $this->owners[$url] ?? '';
            }
            protected function bhskin_instagram_get($path, array $query)
            {
                return $this->instagram + ['_transport' => ['curl_errno' => 0, 'curl_error' => '', 'http_code' => 200, 'json_error' => '']];
            }
        };
    }

    public function testShareLinkResolvesToProfile(): void
    {
        $t = $this->template(['https://vt.tiktok.com/ZS4DKJSLk/' => 'https://www.tiktok.com/@indah.khairun_/photo/7668606028178541845?_r=1&_t=ZS-98YyU4MIO7p']);
        $r = $t->normalize_influencer_profile_url('Tiktok', 'https://vt.tiktok.com/ZS4DKJSLk/');
        $this->assertTrue($r['ok'], $r['msg']);
        $this->assertSame('https://www.tiktok.com/@indah.khairun_', $r['url']);
    }

    public function testShareLinkToPostWithoutUsernameAsksForOwner(): void
    {
        $post = 'https://www.tiktok.com/@/photo/7590620077720866055?_r=1&u_code=f12bcl015g9md7';
        $t = $this->template(['https://vt.tiktok.com/ZS5AmhsDX/' => $post], [$post => 'yikyut']);
        $r = $t->normalize_influencer_profile_url('Tiktok', 'https://vt.tiktok.com/ZS5AmhsDX/');
        $this->assertTrue($r['ok'], $r['msg']);
        $this->assertSame('https://www.tiktok.com/@yikyut', $r['url']);
        $this->assertSame(['resolve https://vt.tiktok.com/ZS5AmhsDX/', "owner $post"], $t->calls);
    }

    public function testUnknownOwnerKeepsTheExplanation(): void
    {
        $r = $this->template()->normalize_influencer_profile_url('Instagram', 'https://www.instagram.com/reel/DPwUiy_CYbP/');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('post/reel', $r['msg']);
    }

    public function testExpiredShareLinkLandsOnHomeAndFails(): void
    {
        // Verified: an unknown vm. code 302s to https://www.tiktok.com/?_r=1.
        $t = $this->template(['https://vm.tiktok.com/ZMh1abcde/' => 'https://www.tiktok.com/?_r=1']);
        $r = $t->normalize_influencer_profile_url('Tiktok', 'https://vm.tiktok.com/ZMh1abcde/');
        $this->assertFalse($r['ok']);
        $this->assertSame(['resolve https://vm.tiktok.com/ZMh1abcde/'], $t->calls);
    }

    public function testValidUrlMakesNoNetworkCall(): void
    {
        $t = $this->template();
        $this->assertTrue($t->normalize_influencer_profile_url('Instagram', 'https://www.instagram.com/putootiwi?igsh=x')['ok']);
        $this->assertSame([], $t->calls);
    }

    public function testInstagramPrivateAndMissingAccountsAreParkable(): void
    {
        $private = $this->template([], [], ['id' => '1', 'username' => 'a', 'is_private' => true])->get_instagram_account('https://www.instagram.com/a');
        $this->assertFalse($private['status']);
        $this->assertSame('private', $private['account_state']);

        $missing = $this->template([], [], ['status' => false, 'errorMessage' => 'This account does not exist.'])->get_instagram_account('https://www.instagram.com/x');
        $this->assertSame('not_found', $missing['account_state']);
        $this->assertSame('permanent', $missing['error_class']);

        // Any other provider failure is retryable, never parked straight away.
        $other = $this->template([], [], ['status' => false, 'errorMessage' => 'Something went wrong'])->get_instagram_account('https://www.instagram.com/x');
        $this->assertSame('transient', $other['error_class']);
        $this->assertArrayNotHasKey('account_state', $other);
    }

    public function testSuccessClearsFailureState(): void
    {
        $s = Template::influencer_sync_state(['status' => true], 5, true, strtotime('2026-10-16 11:00:00'));
        $this->assertSame(['sync_status' => 'ok', 'sync_error' => null, 'sync_fail_count' => 0, 'sync_attempt_at' => '2026-10-16 11:00:00', 'sync_next_at' => null], $s);
    }

    public function testConfigErrorRecordsNothing(): void
    {
        $this->assertNull(Template::influencer_sync_state(['status' => false, 'error_class' => 'config', 'msg' => 'key'], 0, true, time()));
    }

    public function testDeadInstagramAccountIsParkedAtOnce(): void
    {
        foreach (['not_found', 'private', 'invalid_url'] as $state) {
            $s = Template::influencer_sync_state(['status' => false, 'error_class' => 'permanent', 'account_state' => $state, 'msg' => 'x'], 0, true, time());
            $this->assertSame($state, $s['sync_status']);
            $this->assertNull($s['sync_next_at']);
        }
    }

    public function testTransientFailuresBackOffThenPark(): void
    {
        $now = strtotime('2026-10-16 11:00:00');
        $delays = [];
        for ($fails = 0; $fails < Template::INFLUENCER_SYNC_MAX_FAILS - 1; $fails++) {
            $s = Template::influencer_sync_state(['status' => false, 'error_class' => 'transient', 'msg' => 'timeout'], $fails, true, $now);
            $this->assertSame('retrying', $s['sync_status']);
            $delays[] = (strtotime($s['sync_next_at']) - $now) / 3600;
        }
        $this->assertSame([1, 2, 4, 8, 16, 24], $delays);
        $last = Template::influencer_sync_state(['status' => false, 'error_class' => 'transient', 'msg' => 'timeout'], Template::INFLUENCER_SYNC_MAX_FAILS - 1, true, $now);
        $this->assertSame('failed', $last['sync_status']);
        $this->assertSame(Template::INFLUENCER_SYNC_MAX_FAILS, $last['sync_fail_count']);
    }

    public function testThreadsNeverParks(): void
    {
        $s = Template::influencer_sync_state(['status' => false, 'error_class' => 'empty', 'account_state' => 'not_found', 'msg' => 'x'], 50, false, time());
        $this->assertSame('retrying', $s['sync_status']);
    }

    public function testEligibleSqlExcludesParkedAndBackoff(): void
    {
        $sql = $this->template()->influencer_sync_eligible_sql('2026-10-16 11:00:00');
        $this->assertStringContainsString("NOT IN ('not_found','private','invalid_url','failed')", $sql);
        $this->assertStringContainsString("sync_next_at <= '2026-10-16 11:00:00'", $sql);
    }

    public function testSyncBadgeEscapesProviderText(): void
    {
        $html = $this->template()->influencer_sync_badge(['sync_status' => 'private', 'sync_error' => '<b>x</b>']);
        $this->assertStringContainsString('Akun private', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertSame('', $this->template()->influencer_sync_badge(['sync_status' => 'ok']));
        $this->assertSame('', $this->template()->influencer_sync_badge([]));
    }

    public function testHiddenLikesKeepLastKnownValue(): void
    {
        $hidden = ['data' => ['metric_flags' => 'photo_no_view,like_hidden']];
        $this->assertSame(120, Endorse_sync::carry_hidden_likes('Instagram', $hidden, 0, 120, 90));
        $this->assertSame(150, Endorse_sync::carry_hidden_likes('Instagram', $hidden, 150, 120));
        // Visible likes and other platforms pass through, even when lower.
        $this->assertSame(0, Endorse_sync::carry_hidden_likes('Instagram', ['data' => ['metric_flags' => 'photo_no_view']], 0, 120));
        $this->assertSame(0, Endorse_sync::carry_hidden_likes('Tiktok', $hidden, 0, 120));
        $this->assertSame(7, Endorse_sync::carry_hidden_likes('Instagram', $hidden, 7));
    }
}
