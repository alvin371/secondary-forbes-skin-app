<?php
defined('BASEPATH') or exit('No direct script access allowed');

if (!function_exists('social_auto_fetch_platforms')) {
    function social_auto_fetch_platforms()
    {
        return [
            'Tiktok' => true,
            'Instagram' => true,
            'Threads' => true,
            'Youtube' => false,
            'Twitter' => false,
            'Facebook' => false,
        ];
    }
}

if (!function_exists('is_auto_fetch_platform')) {
    function is_auto_fetch_platform($platform)
    {
        $map = social_auto_fetch_platforms();
        $key = trim((string) $platform);
        return isset($map[$key]) ? (bool) $map[$key] : false;
    }
}

if (!function_exists('detect_platform_from_url')) {
    function detect_platform_from_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $patterns = [
            'Tiktok' => '#(^|\\.)tiktok\\.com#i',
            'Instagram' => '#(^|\\.)instagram\\.com#i',
            'Threads' => '#(^|\\.)threads\\.(?:com|net)#i',
            'Youtube' => '#(^|\\.)(youtube\\.com|youtu\\.be)#i',
            'Twitter' => '#(^|\\.)(twitter\\.com|x\\.com)#i',
            'Facebook' => '#(^|\\.)(facebook\\.com|fb\\.watch|fb\\.com)#i',
        ];

        $host = parse_url($url, PHP_URL_HOST);
        $haystack = $host !== null ? $host : $url;
        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $haystack)) {
                return $platform;
            }
        }

        return '';
    }
}
