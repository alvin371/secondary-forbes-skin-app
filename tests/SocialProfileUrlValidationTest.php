<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/helpers/social_platform_helper.php';

/**
 * Cases are real influencer.url values from production (2026-10-11/16) plus share
 * formats observed live on 2026-10-16 (vt.tiktok.com -> /@user/photo/.. or /@/photo/..,
 * m.tiktok.com/v/ID.html -> /@/video/ID, threads.net -> threads.com).
 */
final class SocialProfileUrlValidationTest extends TestCase
{
    /** @dataProvider accepted */
    public function testAcceptsAndCanonicalizes(string $type, string $url, string $canonical): void
    {
        $r = validate_social_profile_url($type, $url);
        $this->assertTrue($r['ok'], $r['msg']);
        $this->assertSame($canonical, $r['url']);
    }

    public static function accepted(): array
    {
        return [
            ['Tiktok', 'https://www.tiktok.com/@diana.langit ', 'https://www.tiktok.com/@diana.langit'],
            ['Tiktok', 'www.tiktok.com/@ainurchmh', 'https://www.tiktok.com/@ainurchmh'],
            ['Tiktok', 'https://www.tiktok.com/@fitriaahsans?_r=1&_t=ZS-9AA1CX7b0tw', 'https://www.tiktok.com/@fitriaahsans'],
            ['Tiktok', 'https://www.tiktok.com/@indah.khairun_/photo/7668606028178541845', 'https://www.tiktok.com/@indah.khairun_'],
            ['Instagram', 'https://www.instagram.com/putootiwi?utm_source=ig_web_button_share_sheet&igsh=ZDNlZDc0MzIxNw==', 'https://www.instagram.com/putootiwi'],
            ['Instagram', 'https://instagr.am/alvincaffeine/', 'https://www.instagram.com/alvincaffeine'],
            ['Threads', 'https://www.threads.net/@alvincaffeine', 'https://www.threads.com/@alvincaffeine'],
            ['Tiktok', '', ''],
            // Prod rows that used to block saving (2026-10-16 export).
            'bare username' => ['Tiktok', 'putritidur041', 'https://www.tiktok.com/@putritidur041'],
            'bare @username' => ['Instagram', '@alvin.caffeine', 'https://www.instagram.com/alvin.caffeine'],
            'leading garbage' => ['Tiktok', '- https://www.tiktok.com/@fitriaahsans?_r=1&_t=ZS-9AA1CX7b0tw', 'https://www.tiktok.com/@fitriaahsans'],
            'share sheet text' => ['Tiktok', "Lihat profil Indah di TikTok\u{00A0}https://www.tiktok.com/@indah.khairun_?_t=x", 'https://www.tiktok.com/@indah.khairun_'],
            // Short/alternative hosts with the account in the path.
            'ig.me' => ['Instagram', 'https://ig.me/alvincaffeine', 'https://www.instagram.com/alvincaffeine'],
            'ig.me direct' => ['Instagram', 'https://ig.me/m/alvincaffeine', 'https://www.instagram.com/alvincaffeine'],
            'instagram story' => ['Instagram', 'https://www.instagram.com/stories/alvincaffeine/3742581642481993423/', 'https://www.instagram.com/alvincaffeine'],
            'instagram deep link' => ['Instagram', 'instagram.com/_u/alvincaffeine', 'https://www.instagram.com/alvincaffeine'],
            'instagram mobile reels tab' => ['Instagram', 'https://m.instagram.com/alvincaffeine/reels/', 'https://www.instagram.com/alvincaffeine'],
            'threads post' => ['Threads', 'https://www.threads.com/@zuck/post/C8H5FiCBlbV', 'https://www.threads.com/@zuck'],
            'youtube handle' => ['Youtube', 'youtube.com/@chan', 'https://youtube.com/@chan'],
            'youtube bare' => ['Youtube', '@chan', 'https://www.youtube.com/@chan'],
            'fb.me' => ['Facebook', 'https://fb.me/facebook', 'https://fb.me/facebook'],
        ];
    }

    /** @dataProvider rejected */
    public function testRejects(string $type, string $url, string $next, string $msgContains): void
    {
        $r = validate_social_profile_url($type, $url);
        $this->assertFalse($r['ok']);
        $this->assertSame($next, $r['next']);
        $this->assertStringContainsString($msgContains, $r['msg']);
    }

    public static function rejected(): array
    {
        return [
            // Need a network step (Template::normalize_influencer_profile_url).
            'tiktok vt share' => ['Tiktok', 'https://vt.tiktok.com/ZS5AmhsDX/', 'resolve', 'kedaluwarsa'],
            'tiktok vm share' => ['Tiktok', 'https://vm.tiktok.com/ZMh1abcde/', 'resolve', 'kedaluwarsa'],
            'tiktok /t/ share' => ['Tiktok', 'https://www.tiktok.com/t/ZTRabc123/', 'resolve', 'kedaluwarsa'],
            'tiktok old mobile' => ['Tiktok', 'https://m.tiktok.com/v/7590620077720866055.html', 'resolve', 'kedaluwarsa'],
            'tiktok post without username' => ['Tiktok', 'https://www.tiktok.com/@/photo/7590620077720866055?_r=1', 'owner', 'tidak memuat username'],
            'instagram post' => ['Instagram', 'https://www.instagram.com/p/C_AKET4Svj_/', 'owner', 'post/reel'],
            'instagram reel' => ['Instagram', 'https://www.instagram.com/reel/DPwUiy_CYbP/', 'owner', 'post/reel'],
            // Cannot be fixed automatically.
            'tiktok search page' => ['Tiktok', 'https://www.tiktok.com/search?q=hilyacutee1&t=1770344075672', '', 'https://www.tiktok.com/@hilyacutee1'],
            'tiktok url on instagram row' => ['Instagram', 'https://www.tiktok.com/@yikyut/photo/7592161752251501845?_r=1', '', 'Ubah Type ke Tiktok'],
            'instagram url on tiktok row' => ['Tiktok', 'https://www.instagram.com/dewi_arabyah?igsh=ZDNlZDc0MzIxNw==', '', 'Ubah Type ke Instagram'],
            'lookalike domain' => ['Instagram', 'https://instagram.com.evil.io/alvincaffeine', '', 'domain tidak dikenal'],
            'instagram share' => ['Instagram', 'https://www.instagram.com/share/BAabcdefgh', '', 'Link share Instagram'],
            'instagram explore' => ['Instagram', 'https://www.instagram.com/explore/tags/skincare/', '', 'link profil Instagram'],
            'threads post without author' => ['Threads', 'https://www.threads.net/t/C8H5FiCBlbV', '', 'tanpa username'],
            'tiktok bad username' => ['Tiktok', 'https://www.tiktok.com/@user%20x', '', 'tidak valid'],
            'two words' => ['Tiktok', 'putri tidur', '', 'spasi'],
        ];
    }

    public function testDetectMatchesWholeDomainOnly(): void
    {
        $this->assertSame('Tiktok', detect_platform_from_url('https://vt.tiktok.com/ZS5AmhsDX/'));
        $this->assertSame('Twitter', detect_platform_from_url('https://x.com/foo'));
        $this->assertSame('Instagram', detect_platform_from_url('https://ig.me/m/foo'));
        $this->assertSame('', detect_platform_from_url('https://instagram.com.evil.io/a'));
        $this->assertSame('', detect_platform_from_url('https://box.com/x'));
        $this->assertSame('', detect_platform_from_url('https://notinstagram.com/a'));
    }
}
