<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Template.php';

/**
 * Parser contract for the instagram-looter2 adapters in Template.
 *
 * Fixtures are trimmed copies of real responses captured on 2026-10-11
 * (with the same `fields` the adapters request).
 */
final class InstagramLooterParserTest extends TestCase
{
    private function template(array $payload): Template
    {
        return new class ($payload) extends Template {
            private array $payload;
            public function __construct(array $payload) { $this->payload = $payload; }
            protected function bhskin_instagram_get($path, array $query)
            {
                return $this->payload + ['_transport' => ['curl_errno' => 0, 'curl_error' => '', 'http_code' => 200, 'json_error' => '']];
            }
        };
    }

    public function testProfileReadsTopLevelUser(): void
    {
        $r = $this->template([
            'id' => '5586008922',
            'username' => 'alvincaffeine',
            'edge_followed_by' => ['count' => 500],
            'profile_pic_url_hd' => 'https://cdn/x.jpg',
            'edge_owner_to_timeline_media' => ['count' => 20],
        ])->get_instagram_account('https://www.instagram.com/alvincaffeine/?igsh=abc');

        $this->assertTrue($r['status']);
        $this->assertSame('5586008922', $r['data']['account_id']);
        $this->assertSame(500, $r['data']['follower']);
        $this->assertSame(20, $r['data']['media_count']);
        $this->assertSame('https://cdn/x.jpg', $r['data']['img']);
    }

    public function testProfileSurfacesProviderError(): void
    {
        $r = $this->template(['status' => false, 'errorMessage' => 'This account does not exist.'])
            ->get_instagram_account('https://www.instagram.com/nobody/');

        $this->assertFalse($r['status']);
        $this->assertSame('This account does not exist.', $r['msg']);
        $this->assertSame('permanent', $r['error_class']);
    }

    public function testFeedReadsEdgesAndClampsHiddenLikes(): void
    {
        $node = function (array $n) { return ['node' => $n]; };
        $r = $this->template(['data' => ['user' => ['edge_owner_to_timeline_media' => ['edges' => [
            $node(['is_video' => false, 'edge_media_preview_like' => ['count' => 104], 'edge_media_to_comment' => ['count' => 6]]),
            $node(['is_video' => true, 'video_view_count' => 1328, 'edge_media_preview_like' => ['count' => 89], 'edge_media_to_comment' => ['count' => 8]]),
            $node(['is_video' => false, 'like_and_view_counts_disabled' => true, 'edge_media_preview_like' => ['count' => -1], 'edge_media_to_comment' => ['count' => 0]]),
        ]]]]])->get_instagram_post_list('5586008922');

        $this->assertTrue($r['status']);
        $this->assertSame([104, 89, 0], array_column($r['data'], 'like'));
        $this->assertSame([6, 8, 0], array_column($r['data'], 'comment'));
        $this->assertSame([0, 1328, 0], array_column($r['data'], 'view'));
        $this->assertSame([true, false, true], array_column($r['data'], 'is_photo'));
        $this->assertSame([false, false, true], array_column($r['data'], 'like_hidden'));
    }

    public function testFeedWithNullUserIsEmpty(): void
    {
        $r = $this->template(['data' => ['user' => null], 'status' => 'ok'])->get_instagram_post_list('1');

        $this->assertFalse($r['status']);
        $this->assertSame('empty', $r['error_class']);
    }

    public function testPostPrefersPlayCount(): void
    {
        $r = $this->template([
            'status' => true,
            'id' => '3742581642481993423',
            'is_video' => true,
            'video_view_count' => 3802,
            'video_play_count' => 3802,
            'edge_media_preview_like' => ['count' => 89],
            'edge_media_preview_comment' => ['count' => 8],
            'taken_at_timestamp' => 1760370575,
        ])->get_instagram_social_media('https://www.instagram.com/reel/DPwUiy_CYbP/');

        $this->assertTrue($r['status']);
        $this->assertSame('3742581642481993423', $r['data']['content_id']);
        $this->assertSame([89, 8, 3802], [$r['data']['like'], $r['data']['comment'], $r['data']['view']]);
        $this->assertSame(date('Y-m-d', 1760370575), $r['data']['created_at']);
        $this->assertSame('', $r['data']['metric_flags']);
    }

    public function testPostWithHiddenLikesIgnoresPlaceholderCount(): void
    {
        // Real /post answer for CiIdPxQh4lB: hidden likes, yet count is 3.
        $r = $this->template([
            'status' => true,
            'id' => '2920712985381407041',
            'is_video' => false,
            'like_and_view_counts_disabled' => true,
            'edge_media_preview_like' => ['count' => 3],
            'edge_media_preview_comment' => ['count' => 0],
        ])->get_instagram_social_media('https://www.instagram.com/p/CiIdPxQh4lB/');

        $this->assertSame(0, $r['data']['like']);
        $this->assertSame('photo_no_view,like_hidden', $r['data']['metric_flags']);
    }

    public function testPhotoPostHasZeroViewAndMissingPostFails(): void
    {
        $photo = $this->template([
            'status' => true,
            'id' => '3440794391999609087',
            'edge_media_preview_like' => ['count' => 17],
            'edge_media_preview_comment' => ['count' => 0],
        ])->get_instagram_social_media('https://www.instagram.com/p/C_AKET4Svj_/');
        $this->assertTrue($photo['status']);
        $this->assertSame(0, $photo['data']['view']);
        $this->assertSame('photo_no_view', $photo['data']['metric_flags']);

        $missing = $this->template(['status' => false, 'errorMessage' => 'This post does not exist.'])
            ->get_instagram_social_media('https://www.instagram.com/p/XXXX/');
        $this->assertFalse($missing['status']);
        $this->assertSame('This post does not exist.', $missing['msg']);
    }

    public function testSummaryLeavesPhotosOutOfViewAverageAndEr(): void
    {
        // Live /user-feeds2 sample for alvincaffeine: [like, comment, view, is_photo, like_hidden]
        $rows = [[104, 6, 0, true, false], [89, 8, 1328, false, false], [17, 0, 0, true, false], [0, 0, 0, true, true],
            [0, 0, 0, true, true], [0, 4, 468, false, true], [0, 0, 0, true, true], [41, 8, 0, true, false],
            [64, 16, 0, true, false], [64, 0, 0, true, false]];
        $posts = array_map(function ($r) {
            return ['like' => $r[0], 'comment' => $r[1], 'share' => 0, 'collect' => 0, 'view' => $r[2], 'is_photo' => $r[3], 'like_hidden' => $r[4]];
        }, $rows);

        $s = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor()->summarize_social_posts($posts);

        $this->assertSame(10, $s['count']);
        $this->assertSame(8, $s['photo_posts']);
        $this->assertSame(4, $s['hidden_like_posts']);
        $this->assertEqualsWithDelta(898.0, $s['avg_view'], 0.001);      // (1328 + 468) / 2 videos
        // 6 posts with visible likes: 110 + 97 + 17 + 49 + 80 + 64
        $this->assertEqualsWithDelta(69.5, $s['avg_interaksi'], 0.001);
        // ER only from the one video with visible likes: 97 / 1328
        $this->assertEqualsWithDelta(7.3042, $s['er'], 0.001);
        $this->assertSame(1796, $s['sum']['view']);
    }

    public function testSummaryOfAllPhotoFeedHasNoEr(): void
    {
        $s = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor()
            ->summarize_social_posts([['like' => 50, 'comment' => 2, 'view' => 0, 'is_photo' => true]]);
        $this->assertSame(0, $s['avg_view']);
        $this->assertSame(0, $s['er']);
    }

    public function testSummaryWithoutFlagsMatchesPlainAverage(): void
    {
        // Threads/TikTok-shaped posts carry no is_photo flag: every post counts.
        $s = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor()
            ->summarize_social_posts([['like' => 10, 'comment' => 0, 'view' => 100], ['like' => 30, 'comment' => 0, 'view' => 300]]);
        $this->assertEqualsWithDelta(200.0, $s['avg_view'], 0.001);
        $this->assertEqualsWithDelta(10.0, $s['er'], 0.001);
    }

    public function testSummaryWhereEveryVideoHidesLikesHasNoEr(): void
    {
        // Shape of rnldyaldy (influencer 690): mostly photos, likes hidden almost everywhere.
        $s = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor()->summarize_social_posts([
            ['like' => 0, 'comment' => 3, 'view' => 900, 'is_photo' => false, 'like_hidden' => true],
            ['like' => 0, 'comment' => 1, 'view' => 0, 'is_photo' => true, 'like_hidden' => true],
            ['like' => 40, 'comment' => 2, 'view' => 0, 'is_photo' => true, 'like_hidden' => false],
        ]);
        $this->assertEqualsWithDelta(900.0, $s['avg_view'], 0.001);
        $this->assertEqualsWithDelta(42.0, $s['avg_interaksi'], 0.001);
        $this->assertSame(0, $s['er']);
    }
}
