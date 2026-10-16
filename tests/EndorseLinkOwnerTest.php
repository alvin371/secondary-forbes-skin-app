<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/helpers/social_platform_helper.php';
require_once __DIR__ . '/../application/libraries/Template.php';

/**
 * Creator username from an endorse link_upload. Endorse::store()/update() used to accept only
 * tiktok.com/@user/..., so every Instagram/Threads/YouTube link failed with
 * "Link upload tidak valid atau tidak dapat mengambil username.".
 */
final class EndorseLinkOwnerTest extends TestCase
{
    /** @dataProvider named */
    public function testReadsCreatorFromPostLink(string $platform, string $link, string $username): void
    {
        $r = parse_endorse_post_link($platform, $link);
        $this->assertTrue($r['ok'], $r['msg']);
        $this->assertSame($username, $r['username']);
    }

    public static function named(): array
    {
        return [
            ['Tiktok', 'https://www.tiktok.com/@indah.khairun_/photo/7668606028178541845?_r=1', 'indah.khairun_'],
            ['Tiktok', 'https://www.tiktok.com/@diana.langit/video/7590620077720866055', 'diana.langit'],
            'google docs wrapper (prod row 13943)' => ['Tiktok', 'https://www.google.com/url?q=https://www.tiktok.com/@azaleabykeisha/photo/7681557303639756053&sa=D&source=editors', 'azaleabykeisha'],
            'instagram owner in path (checked live)' => ['Instagram', 'https://www.instagram.com/egagofur_/reel/DPwUiy_CYbP/', 'egagofur_'],
            ['Threads', 'https://www.threads.com/@zuck/post/C8H5FiCBlbV', 'zuck'],
            ['Twitter', 'https://x.com/abc/status/123', 'abc'],
            'youtube: creator comes from the form' => ['Youtube', 'https://youtu.be/dQw4w9WgXcQ', ''],
        ];
    }

    /** @dataProvider rejected */
    public function testRejects(string $platform, string $link, string $next, string $msgContains): void
    {
        $r = parse_endorse_post_link($platform, $link);
        $this->assertFalse($r['ok']);
        $this->assertSame($next, $r['next']);
        $this->assertStringContainsString($msgContains, $r['msg']);
    }

    public static function rejected(): array
    {
        return [
            // /post answers "This post does not exist." for a profile URL (checked live).
            'instagram profile' => ['Instagram', 'https://www.instagram.com/alvincaffeine/', '', 'link profil Instagram'],
            'tiktok profile' => ['Tiktok', 'https://www.tiktok.com/@abc/', '', 'link profil TikTok'],
            'platform mismatch' => ['Instagram', 'https://www.tiktok.com/@abc/video/1', '', 'Ubah Platform ke Tiktok'],
            'instagram post without owner' => ['Instagram', 'https://www.instagram.com/reel/DPwUiy_CYbP/?igsh=x', 'owner', 'post/reel'],
            'tiktok share' => ['Tiktok', 'https://vt.tiktok.com/ZS4DKJSLk/', 'resolve', 'kedaluwarsa'],
            'tiktok post without username' => ['Tiktok', 'https://www.tiktok.com/@/photo/7590620077720866055', 'owner', 'tidak memuat username'],
        ];
    }

    private function template(array $redirects, array $owners): Template
    {
        return new class ($redirects, $owners) extends Template {
            private array $redirects;
            private array $owners;
            public function __construct(array $redirects, array $owners) { $this->redirects = $redirects; $this->owners = $owners; }
            protected function resolve_social_redirect($url) { return $this->redirects[$url] ?? ''; }
            protected function lookup_social_post_owner($type, $url) { return $this->owners[$url] ?? ''; }
        };
    }

    public function testInstagramPostAsksThePostOwner(): void
    {
        $link = 'https://www.instagram.com/reel/DPwUiy_CYbP/';
        $r = $this->template([], [$link => 'egagofur_'])->endorse_link_owner('Instagram', $link);
        $this->assertTrue($r['ok']);
        $this->assertSame('egagofur_', $r['username']);
        $this->assertSame($link, $r['url']);
    }

    public function testShareLinkKeepsThePastedLinkButReadsTheRedirect(): void
    {
        $r = $this->template(['https://vt.tiktok.com/ZS4DKJSLk/' => 'https://www.tiktok.com/@indah.khairun_/photo/7668606028178541845?_r=1'], [])
            ->endorse_link_owner('Tiktok', ' https://vt.tiktok.com/ZS4DKJSLk/ ');
        $this->assertTrue($r['ok'], $r['msg']);
        $this->assertSame('indah.khairun_', $r['username']);
        $this->assertSame('https://vt.tiktok.com/ZS4DKJSLk/', $r['url']);
    }

    public function testUnknownOwnerFails(): void
    {
        $r = $this->template([], [])->endorse_link_owner('Instagram', 'https://www.instagram.com/p/C_AKET4Svj_/');
        $this->assertFalse($r['ok']);
    }
}
