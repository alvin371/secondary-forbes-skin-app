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

if (!function_exists('social_platform_domains')) {
    /**
     * Registrable domains per platform. A host matches when it equals one of these or
     * is a subdomain of it (www., m., vt., vm. ...), so "instagram.com.evil.io" does not.
     * Aliases: instagr.am and ig.me are Instagram short hosts (same path), threads.net 301s
     * to threads.com, youtu.be is YouTube's video short host, fb.me 302s to facebook.com.
     */
    function social_platform_domains()
    {
        return [
            'Tiktok' => ['tiktok.com'],
            'Instagram' => ['instagram.com', 'instagr.am', 'ig.me'],
            'Threads' => ['threads.com', 'threads.net'],
            'Youtube' => ['youtube.com', 'youtu.be'],
            'Twitter' => ['twitter.com', 'x.com'],
            'Facebook' => ['facebook.com', 'fb.com', 'fb.me', 'fb.watch'],
        ];
    }
}

if (!function_exists('social_url_host')) {
    /** Lowercased host of a URL; scheme-less input ("www.tiktok.com/@a") is accepted. */
    function social_url_host($url)
    {
        $url = trim((string) $url);
        if ($url === '') return '';
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . $url;
        return strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
    }
}

if (!function_exists('detect_platform_from_url')) {
    function detect_platform_from_url($url)
    {
        $host = social_url_host($url);
        if ($host === '') return '';
        foreach (social_platform_domains() as $platform => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                    return $platform;
                }
            }
        }
        return '';
    }
}

if (!function_exists('social_profile_url_formats')) {
    /** Canonical profile URL per platform (sprintf with the username). */
    function social_profile_url_formats()
    {
        return [
            'Tiktok' => 'https://www.tiktok.com/@%s',
            'Instagram' => 'https://www.instagram.com/%s',
            'Threads' => 'https://www.threads.com/@%s',
            'Youtube' => 'https://www.youtube.com/@%s',
            'Twitter' => 'https://x.com/%s',
            'Facebook' => 'https://www.facebook.com/%s',
        ];
    }
}

if (!function_exists('social_extract_url')) {
    /**
     * Pull the link out of pasted text: share sheets prepend words ("Lihat profil ... https://..")
     * and prod has "- https://www.tiktok.com/@..". Text without a scheme is returned trimmed.
     */
    function social_extract_url($text)
    {
        // \x{00A0}: copy-paste from browsers/WhatsApp often carries a non-breaking space.
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $text) ?? (string) $text);
        if (preg_match('#https?://\S+#i', $text, $m)) return rtrim($m[0], '.,;)');
        return $text;
    }
}

if (!function_exists('validate_social_profile_url')) {
    /**
     * Check an influencer profile URL against its declared type and return the canonical
     * profile URL. Sync code reads the username from the first path segment, so a post,
     * search or short link would fetch someone else's account (seen in prod: two
     * influencers stored tiktok.com/search?q=.. both synced the same "search" account).
     *
     * Pure: no network. When the account can only be known by asking the platform, it
     * fails with `next`, which Template::normalize_influencer_profile_url() acts on:
     * - 'resolve': share link that redirects (vt./vm.tiktok.com, tiktok.com/t/, m.tiktok.com/v/).
     * - 'owner': post link without a username (tiktok.com/@/photo/ID, instagram.com/p|reel/CODE).
     *
     * @return array ['ok' => bool, 'url' => canonical url, 'username' => string, 'msg' => string, 'next' => '', 'resolve' or 'owner']
     */
    function validate_social_profile_url($type, $url)
    {
        $type = trim((string) $type);
        $url = social_extract_url($url);
        $result = function ($ok, $url, $username = '', $msg = '', $next = '') {
            return ['ok' => $ok, 'url' => $url, 'username' => $username, 'msg' => $msg, 'next' => $next];
        };
        $fail = function ($msg, $next = '') use ($url, $result) { return $result(false, $url, '', $msg, $next); };
        // The form does not require a URL; an empty one just means "not synced".
        if ($url === '') return $result(true, '');

        $formats = social_profile_url_formats();
        if (!isset($formats[$type])) return $result(true, $url);
        $namePattern = '/^[A-Za-z0-9._]{1,30}$/';
        // Bare username ("putritidur041", "@putri"): the type says which platform it is.
        if (strpos($url, '/') === false && detect_platform_from_url($url) === '' && preg_match($namePattern, ltrim($url, '@'))) {
            $username = ltrim($url, '@');
            return $result(true, sprintf($formats[$type], $username), $username);
        }
        if (preg_match('/\s/', $url)) return $fail('URL profil tidak boleh mengandung spasi.');

        $platform = detect_platform_from_url($url);
        if ($platform !== $type) {
            return $fail($platform !== ''
                ? 'URL ini link ' . $platform . ', sedangkan Type = ' . $type . '. Ubah Type ke ' . $platform . ' atau ganti URL-nya.'
                : 'URL bukan link ' . $type . ' (domain tidak dikenal). Pastikan Type dan URL sesuai.');
        }

        $host = social_url_host($url);
        $full = preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'https://' . $url;
        $segments = array_values(array_filter(explode('/', (string) parse_url($full, PHP_URL_PATH)), 'strlen'));
        $first = $segments[0] ?? '';
        $second = $segments[1] ?? '';
        $profile = function ($username, $msg) use ($type, $formats, $namePattern, $result, $fail) {
            return preg_match($namePattern, $username)
                ? $result(true, sprintf($formats[$type], $username), $username)
                : $fail($msg);
        };

        if ($type === 'Tiktok') {
            $example = 'Gunakan link profil TikTok, contoh https://www.tiktok.com/@username.';
            // Share links only say who they belong to after the redirect (verified 2026-10-16:
            // vt.tiktok.com/ZS4DKJSLk -> /@indah.khairun_/photo/..; m.tiktok.com/v/ID.html -> /@/video/ID).
            if (in_array($host, ['vt.tiktok.com', 'vm.tiktok.com'], true) || $first === 't' || ($host === 'm.tiktok.com' && $first === 'v')) {
                return $fail('Link share TikTok tidak bisa dibuka. Link mungkin sudah kedaluwarsa; ' . lcfirst($example), 'resolve');
            }
            if (!in_array($host, ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'], true)) return $fail($example);
            if ($first === 'search') {
                parse_str((string) parse_url($full, PHP_URL_QUERY), $query);
                $q = ltrim(trim(strval($query['q'] ?? '')), '@');
                return $fail('Ini link pencarian TikTok, bukan profil. '
                    . (preg_match($namePattern, $q) ? 'Jika maksudnya akun @' . $q . ', isi https://www.tiktok.com/@' . $q . '.' : $example));
            }
            if (strpos($first, '@') !== 0) return $fail($example);
            if ($first === '@' && in_array($second, ['video', 'photo'], true) && isset($segments[2])) {
                return $fail('Link post TikTok ini tidak memuat username, dan pemilik post tidak bisa dibaca. ' . $example, 'owner');
            }
            return $profile(substr($first, 1), 'Username TikTok pada URL tidak valid.');
        }
        if ($type === 'Instagram') {
            $example = 'Gunakan link profil Instagram, contoh https://www.instagram.com/username.';
            // ig.me/<user> and ig.me/m/<user> (Direct) both name the account in the path.
            if ($host === 'ig.me') return $profile($first === 'm' ? $second : $first, $example);
            if (in_array($first, ['p', 'reel', 'reels', 'tv'], true) && $second !== '') {
                return $fail('Ini link post/reel Instagram dan pemilik akunnya tidak bisa dibaca. ' . $example, 'owner');
            }
            // /stories/<user>/<id> and the app deep link /_u/<user>.
            if (in_array($first, ['stories', '_u'], true)) return $profile($second, $example);
            if ($first === 'share') {
                return $fail('Link share Instagram tidak bisa dibaca otomatis (Instagram menolak akses server). Buka link-nya, lalu salin link profil. ' . $example);
            }
            $reserved = ['explore', 'accounts', 'direct', 's', 'p', 'reel', 'reels', 'tv'];
            if (in_array(strtolower($first), $reserved, true)) return $fail($example);
            return $profile(ltrim($first, '@'), $example);
        }
        if ($type === 'Threads') {
            $example = 'Gunakan link profil Threads, contoh https://www.threads.com/@username.';
            // threads.com/t/CODE (also via threads.net 301) is a post without its author.
            if ($first === 't') return $fail('Ini link post Threads tanpa username. ' . $example);
            if (strpos($first, '@') !== 0) return $fail($example);
            return $profile(substr($first, 1), $example);
        }
        // Youtube/Twitter/Facebook are not auto-fetched: domain check is enough.
        return $result(true, $full);
    }
}
