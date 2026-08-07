<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Endorse_analytics_v2 — pure metric core for the Analytics V2 read model.
 *
 * Free of database access so every rule can be unit-tested in isolation,
 * mirroring the convention established by Endorse_repair_plan.
 *
 * The historical `endorse_logs.views` column is unreliable for 2026-07-27 ..
 * 2026-08-05 because daily rows were mutable. `views_after` — the observed
 * cumulative snapshot — was never corrupted and is the only source used here.
 * Nothing in this class or its callers writes to endorse_logs.
 *
 * Two product metrics:
 *
 *   total_views_terakhir_disinkronkan(D)
 *       = SUM over canonical content of the latest successfully synchronised
 *         cumulative value at or before D (carried forward when a sync failed).
 *
 *   kenaikan_views(D)
 *       = SUM over canonical content of MAX(0, latest(D) - latest(previous
 *         successful sync)), counted only on dates that synchronised
 *         successfully and that have an earlier successful sync.
 *
 * Opening views (the first successful sync of a content) are reported
 * separately and never counted as growth: views that already existed when we
 * first looked were not earned that day.
 */
class Endorse_analytics_v2
{
    // ---------------------------------------------------------- daily states

    /** Synced successfully and has an earlier successful sync. */
    const STATE_BERHASIL = 'berhasil';
    /** First successful sync for this content — opening views, no growth. */
    const STATE_DATA_AWAL = 'data_awal';
    /** Sync failed or produced nothing; previous total carried forward. */
    const STATE_GAGAL = 'gagal';
    /** In scope but never successfully synchronised — no value at all. */
    const STATE_BELUM_PERNAH = 'belum_pernah_berhasil';

    // ------------------------------------------------------ data completeness

    const LENGKAP = 'lengkap';
    const SEBAGIAN_LENGKAP = 'sebagian_lengkap';
    const TIDAK_MEMADAI = 'tidak_memadai';

    /** Above this failure ratio a date is 'tidak_memadai' rather than partial. */
    const GAGAL_RATIO_MAX = 0.34;

    /** Bumped whenever the arithmetic changes; surfaced as meta.versi_kalkulasi. */
    const CALCULATION_VERSION = 'v2.1.0';

    /** Canonical is the confirmed default: one content id contributes once. */
    const POPULATION_CANONICAL = 'canonical';
    const POPULATION_RAW = 'raw';

    // Compatibility aliases for the read-only repair comparison command. They
    // are diagnostics only; the V2 endpoint exposes the Indonesian contract.
    const OBS_GROWTH = 'growth';
    const OBS_OPENING = 'opening';
    const OBS_NEGATIVE = 'negative';

    const TIMEZONE = 'Asia/Jakarta';

    /** Indonesian month names for human-readable dates. */
    const BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /**
     * User-facing failure reasons in Bahasa Indonesia, keyed by the machine
     * class produced by Endorse_sync. The technical message is preserved
     * alongside for diagnostics but is never the primary UI copy.
     */
    const ALASAN_GAGAL = [
        'data_quality' => 'Konten tidak dapat dibaca oleh penyedia data',
        'permanent' => 'URL konten tidak valid',
        'empty' => 'Konten tidak dapat dibaca oleh penyedia data',
        'transient' => 'Penyedia data sedang bermasalah',
        'infra' => 'Penyedia data sedang bermasalah',
        'infra_dns' => 'Penyedia data sedang bermasalah',
        'infra_connect' => 'Penyedia data sedang bermasalah',
        'infra_tls' => 'Penyedia data sedang bermasalah',
        'infra_stall' => 'Waktu permintaan habis',
        'config' => 'Konfigurasi penyedia data bermasalah',
        'belum_pernah' => 'Belum pernah berhasil disinkronkan',
        'anomali_negatif' => 'Nilai total menurun dan perlu diperiksa',
    ];

    /** Translate a machine failure class into Indonesian UI copy. */
    public static function alasan_gagal(string $errorClass, string $fallback = ''): string
    {
        $key = strtolower(trim($errorClass));
        if (isset(self::ALASAN_GAGAL[$key])) {
            return self::ALASAN_GAGAL[$key];
        }
        return $fallback !== '' ? $fallback : 'Sinkronisasi gagal karena alasan yang tidak diketahui';
    }

    // -------------------------------------------------------- per-content day

    /**
     * Resolve one canonical content's state on one date.
     *
     * @param array $args {
     *   observed_views: int|null  cumulative value synced on this date, null when no successful sync
     *   previous_views: int|null  latest successful cumulative value before this date
     *   is_first_success: bool    this date is the content's first successful sync
     *   in_scope: bool            content had been posted / seen on or before this date
     * }
     * @return array {state, total, kenaikan, opening, anomali, selisih_mentah, carried}
     */
    public static function resolve_content_day(array $args): array
    {
        $observed = array_key_exists('observed_views', $args) && $args['observed_views'] !== null
            ? intval($args['observed_views']) : null;
        $previous = array_key_exists('previous_views', $args) && $args['previous_views'] !== null
            ? intval($args['previous_views']) : null;

        $out = [
            'state' => self::STATE_GAGAL,
            'total' => 0,
            'kenaikan' => 0,
            'opening' => 0,
            'anomali' => false,
            'selisih_mentah' => 0,
            'carried' => false,
        ];

        // Never successfully synchronised: there is no trustworthy value at all.
        // It must not contribute a zero-view total, which would understate the
        // campaign as though the post genuinely had no views.
        if ($observed === null && $previous === null) {
            $out['state'] = self::STATE_BELUM_PERNAH;
            return $out;
        }

        // Sync failed on this date. Show the last successful total, mark it as
        // carried forward, and show zero increase — never a trustworthy zero.
        if ($observed === null) {
            $out['state'] = self::STATE_GAGAL;
            $out['total'] = $previous;
            $out['kenaikan'] = 0;
            $out['carried'] = true;
            return $out;
        }

        $out['total'] = $observed;

        // First successful sync: these views existed before we were watching.
        // They are opening views ("Data awal"), never daily growth.
        if ($previous === null || !empty($args['is_first_success'])) {
            $out['state'] = self::STATE_DATA_AWAL;
            $out['opening'] = $observed;
            $out['kenaikan'] = 0;
            return $out;
        }

        $raw = $observed - $previous;
        $out['state'] = self::STATE_BERHASIL;
        $out['selisih_mentah'] = $raw;

        // A cumulative total cannot legitimately fall. Display zero so no bar is
        // negative, but keep the signed difference and raise an anomaly so the
        // drop is investigated rather than hidden.
        if ($raw < 0) {
            $out['kenaikan'] = 0;
            $out['anomali'] = true;
            return $out;
        }

        $out['kenaikan'] = $raw;
        return $out;
    }

    /**
     * Compatibility adapter used by the historical repair oracle. It does not
     * participate in dashboard aggregation and performs no writes.
     */
    public static function classify_observation(array $row): array
    {
        $day = self::resolve_content_day([
            'observed_views' => array_key_exists('views_after', $row) ? intval($row['views_after']) : null,
            'previous_views' => array_key_exists('prev_after', $row) && $row['prev_after'] !== null ? intval($row['prev_after']) : null,
            'is_first_success' => !isset($row['prev_after']) || $row['prev_after'] === null,
        ]);
        $class = self::OBS_GROWTH;
        if ($day['state'] === self::STATE_DATA_AWAL) $class = self::OBS_OPENING;
        if (!empty($day['anomali'])) $class = self::OBS_NEGATIVE;
        return ['class' => $class, 'growth' => intval($day['kenaikan']), 'opening' => intval($day['opening']),
            'negative_views' => intval($day['selisih_mentah'])];
    }

    /**
     * Walk one canonical content across the reporting dates.
     *
     * Growth is computed per content here, and only summed afterwards by
     * aggregate_dates(). Campaign growth is never derived by subtracting whole
     * campaign totals, because the included post population changes daily.
     *
     * @param array $observations map of date => cumulative views (successful syncs only)
     * @param array $dates        reporting dates, ascending
     * @param string|null $enteredAt date the content entered scope (Y-m-d)
     * @param int|null $priorViews latest successful value from BEFORE the range
     * @param bool $everSyncedBefore whether a successful sync exists before the range
     * @return array date => resolve_content_day() result
     */
    public static function build_content_series(
        array $observations,
        array $dates,
        ?string $enteredAt = null,
        ?int $priorViews = null,
        bool $everSyncedBefore = false
    ): array {
        $series = [];
        $previous = $priorViews;
        $seenSuccess = $everSyncedBefore;

        foreach ($dates as $d) {
            // Not yet in scope — contributes nothing, not even a failure.
            if ($enteredAt !== null && $enteredAt !== '' && $d < $enteredAt && !isset($observations[$d])) {
                continue;
            }

            $observed = array_key_exists($d, $observations) && $observations[$d] !== null
                ? intval($observations[$d]) : null;

            $day = self::resolve_content_day([
                'observed_views' => $observed,
                'previous_views' => $previous,
                'is_first_success' => ($observed !== null && !$seenSuccess),
            ]);

            if ($observed !== null) {
                $previous = $observed;
                $seenSuccess = true;
            }

            $series[$d] = $day;
        }

        return $series;
    }

    // ------------------------------------------------------------- aggregation

    /**
     * Aggregate per-content daily series into per-date totals.
     *
     * @param array $seriesByContent content_id => (date => day result)
     * @param array $dates reporting dates, ascending
     * @param array $ctx {
     *   sync_times: date => ['terbaru' => ts, 'terlama' => ts],
     *   duplicate_groups: date => int,
     *   duplicate_rows: date => int
     * }
     * @return array date => aggregated bucket
     */
    public static function aggregate_dates(array $seriesByContent, array $dates, array $ctx = []): array
    {
        $syncTimes = $ctx['sync_times'] ?? [];
        $dupGroups = $ctx['duplicate_groups'] ?? [];
        $dupRows = $ctx['duplicate_rows'] ?? [];

        $buckets = [];
        foreach ($dates as $d) {
            $buckets[$d] = self::empty_bucket($d);
        }

        foreach ($seriesByContent as $series) {
            foreach ($series as $date => $day) {
                if (!isset($buckets[$date])) {
                    $buckets[$date] = self::empty_bucket($date);
                }
                $b =& $buckets[$date];

                $b['jumlah_post']++;
                $b['total_views_terakhir_disinkronkan'] += intval($day['total']);
                $b['kenaikan_views'] += intval($day['kenaikan']);
                $b['opening_views'] += intval($day['opening']);

                switch ($day['state']) {
                    case self::STATE_BERHASIL:
                    case self::STATE_DATA_AWAL:
                        $b['jumlah_berhasil']++;
                        if ($day['state'] === self::STATE_DATA_AWAL) {
                            $b['jumlah_data_awal']++;
                        }
                        break;
                    case self::STATE_GAGAL:
                        $b['jumlah_gagal']++;
                        $b['jumlah_menggunakan_data_terakhir']++;
                        break;
                    case self::STATE_BELUM_PERNAH:
                        $b['jumlah_gagal']++;
                        $b['jumlah_belum_pernah_berhasil']++;
                        break;
                }

                if (!empty($day['anomali'])) {
                    $b['jumlah_anomali']++;
                    $b['selisih_negatif'] += intval($day['selisih_mentah']);
                }
                unset($b);
            }
        }

        foreach ($buckets as $date => $bucket) {
            $b =& $buckets[$date];
            $b['jumlah_grup_duplikat'] = intval($dupGroups[$date] ?? 0);
            $b['jumlah_baris_duplikat'] = intval($dupRows[$date] ?? 0);
            $b['sinkronisasi_terbaru'] = $syncTimes[$date]['terbaru'] ?? null;
            $b['sinkronisasi_terlama'] = $syncTimes[$date]['terlama'] ?? null;
            $b['memiliki_anomali'] = $b['jumlah_anomali'] > 0;
            $b['cakupan_persen'] = self::cakupan_persen($b['jumlah_berhasil'], $b['jumlah_post']);
            $b['kelengkapan_data'] = self::kelengkapan($b['jumlah_berhasil'], $b['jumlah_post']);
            $b['tanggal_label'] = self::tanggal_label($date);
            unset($b);
        }

        return $buckets;
    }

    private static function empty_bucket(string $date): array
    {
        return [
            'tanggal' => $date,
            'tanggal_label' => self::tanggal_label($date),
            'total_views_terakhir_disinkronkan' => 0,
            'kenaikan_views' => 0,
            'opening_views' => 0,
            'jumlah_post' => 0,
            'jumlah_berhasil' => 0,
            'jumlah_gagal' => 0,
            'jumlah_data_awal' => 0,
            'jumlah_belum_pernah_berhasil' => 0,
            'jumlah_menggunakan_data_terakhir' => 0,
            'jumlah_grup_duplikat' => 0,
            'jumlah_baris_duplikat' => 0,
            'jumlah_anomali' => 0,
            'selisih_negatif' => 0,
            'memiliki_anomali' => false,
            'cakupan_persen' => 0,
            'sinkronisasi_terbaru' => null,
            'sinkronisasi_terlama' => null,
            'kelengkapan_data' => self::TIDAK_MEMADAI,
        ];
    }

    /** Coverage as a whole percentage of in-scope posts that synced. */
    public static function cakupan_persen(int $berhasil, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }
        return intval(round($berhasil / $total * 100));
    }

    /**
     * Data completeness for one date.
     *
     * lengkap          — every in-scope post synchronised successfully.
     * sebagian_lengkap — some failed, but the successful majority is usable.
     * tidak_memadai    — too little synchronised to characterise the date.
     */
    public static function kelengkapan(int $berhasil, int $total): string
    {
        if ($total <= 0 || $berhasil <= 0) {
            return self::TIDAK_MEMADAI;
        }
        $gagalRatio = ($total - $berhasil) / $total;
        if ($gagalRatio > self::GAGAL_RATIO_MAX) {
            return self::TIDAK_MEMADAI;
        }
        return $gagalRatio == 0.0 ? self::LENGKAP : self::SEBAGIAN_LENGKAP;
    }

    /** Indonesian label for a completeness status. */
    public static function kelengkapan_label(string $status): string
    {
        $map = [
            self::LENGKAP => 'Lengkap',
            self::SEBAGIAN_LENGKAP => 'Sebagian lengkap',
            self::TIDAK_MEMADAI => 'Tidak memadai',
        ];
        return $map[$status] ?? 'Tidak memadai';
    }

    // ----------------------------------------------------------------- summary

    /**
     * Period summary.
     *
     * total_views_terakhir_disinkronkan is the LAST reporting date's total, not
     * a sum across dates — summing cumulative snapshots would multiply the same
     * views by the number of days in range.
     *
     * @param array $dates aggregated buckets, ascending
     * @param bool  $hasDateFilter true when the user selected a date range
     */
    public static function summarize(array $dates, bool $hasDateFilter = true): array
    {
        $summary = [
            'total_views_terakhir_disinkronkan' => 0,
            'total_kenaikan_views' => 0,
            'total_opening_views' => 0,
            'jumlah_post' => 0,
            'jumlah_berhasil' => 0,
            'jumlah_gagal' => 0,
            'jumlah_data_awal' => 0,
            'jumlah_belum_pernah_berhasil' => 0,
            'jumlah_menggunakan_data_terakhir' => 0,
            'jumlah_grup_duplikat' => 0,
            'jumlah_baris_duplikat' => 0,
            'jumlah_anomali' => 0,
            'cakupan_persen' => 0,
            'tanggal_terakhir' => null,
            'tanggal_label_terakhir' => null,
            'kenaikan_hari_terakhir' => 0,
            'jumlah_hari_teramati' => 0,
            'rata_rata_kenaikan_harian' => 0,
            'kenaikan_tertinggi' => 0,
            'tanggal_kenaikan_tertinggi' => null,
            'sinkronisasi_terbaru' => null,
            'sinkronisasi_terlama' => null,
            'kelengkapan_data' => self::TIDAK_MEMADAI,
            'menggunakan_filter_tanggal' => $hasDateFilter,
        ];

        $last = null;
        $worst = self::LENGKAP;

        foreach ($dates as $d) {
            $summary['total_kenaikan_views'] += intval($d['kenaikan_views'] ?? 0);
            $summary['total_opening_views'] += intval($d['opening_views'] ?? 0);
            $summary['jumlah_anomali'] += intval($d['jumlah_anomali'] ?? 0);

            $summary['jumlah_grup_duplikat'] = max(
                $summary['jumlah_grup_duplikat'], intval($d['jumlah_grup_duplikat'] ?? 0)
            );
            $summary['jumlah_baris_duplikat'] = max(
                $summary['jumlah_baris_duplikat'], intval($d['jumlah_baris_duplikat'] ?? 0)
            );

            $summary['sinkronisasi_terbaru'] = self::max_ts(
                $summary['sinkronisasi_terbaru'], $d['sinkronisasi_terbaru'] ?? null
            );
            $summary['sinkronisasi_terlama'] = self::min_ts(
                $summary['sinkronisasi_terlama'], $d['sinkronisasi_terlama'] ?? null
            );

            if (intval($d['jumlah_post'] ?? 0) > 0) {
                $last = $d;
                $summary['jumlah_hari_teramati']++;

                $kenaikan = intval($d['kenaikan_views'] ?? 0);
                if ($summary['tanggal_kenaikan_tertinggi'] === null || $kenaikan > $summary['kenaikan_tertinggi']) {
                    $summary['kenaikan_tertinggi'] = $kenaikan;
                    $summary['tanggal_kenaikan_tertinggi'] = strval($d['tanggal'] ?? '');
                }
                $worst = self::worse_kelengkapan($worst, strval($d['kelengkapan_data'] ?? self::TIDAK_MEMADAI));
            }
        }

        if ($last !== null) {
            // The headline total is the newest reporting date that produced data.
            $summary['total_views_terakhir_disinkronkan'] = intval($last['total_views_terakhir_disinkronkan']);
            $summary['jumlah_post'] = intval($last['jumlah_post']);
            $summary['jumlah_berhasil'] = intval($last['jumlah_berhasil']);
            $summary['jumlah_gagal'] = intval($last['jumlah_gagal']);
            $summary['jumlah_data_awal'] = intval($last['jumlah_data_awal']);
            $summary['jumlah_belum_pernah_berhasil'] = intval($last['jumlah_belum_pernah_berhasil']);
            $summary['jumlah_menggunakan_data_terakhir'] = intval($last['jumlah_menggunakan_data_terakhir']);
            $summary['cakupan_persen'] = intval($last['cakupan_persen']);
            $summary['tanggal_terakhir'] = strval($last['tanggal']);
            $summary['tanggal_label_terakhir'] = strval($last['tanggal_label']);
            $summary['kenaikan_hari_terakhir'] = intval($last['kenaikan_views']);
            $summary['kelengkapan_data'] = $worst;
        }

        // Averaged over days that produced data. Dividing by calendar days would
        // let a day we never polled drag the average down as if growth were zero.
        if ($summary['jumlah_hari_teramati'] > 0) {
            $summary['rata_rata_kenaikan_harian'] = intval(round(
                $summary['total_kenaikan_views'] / $summary['jumlah_hari_teramati']
            ));
        }

        // Without a date filter the headline increase is the newest date's, not
        // a period sum, per the confirmed product definition.
        if (!$hasDateFilter) {
            $summary['total_kenaikan_views'] = $summary['kenaikan_hari_terakhir'];
        }

        return $summary;
    }

    /** Completeness is reported at its weakest link across the period. */
    public static function worse_kelengkapan(string $a, string $b): string
    {
        $rank = [self::LENGKAP => 0, self::SEBAGIAN_LENGKAP => 1, self::TIDAK_MEMADAI => 2];
        return ($rank[$b] ?? 2) > ($rank[$a] ?? 2) ? $b : $a;
    }

    private static function max_ts(?string $a, ?string $b): ?string
    {
        if ($b === null || $b === '') return $a;
        if ($a === null || $a === '') return $b;
        return $b > $a ? $b : $a;
    }

    private static function min_ts(?string $a, ?string $b): ?string
    {
        if ($b === null || $b === '') return $a;
        if ($a === null || $a === '') return $b;
        return $b < $a ? $b : $a;
    }

    // -------------------------------------------------------------- formatting

    /** "5 Agustus 2026" — Indonesian long date. */
    public static function tanggal_label(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $date;
        }
        $bulan = self::BULAN[intval($m[2])] ?? $m[2];
        return intval($m[3]) . ' ' . $bulan . ' ' . $m[1];
    }

    /**
     * "7 Agustus 2026, 10:42:17 WIB" — full timestamp with seconds and zone.
     * Never abbreviated: the product requires date, hour, minute, second, WIB.
     */
    public static function waktu_label(?string $timestamp): ?string
    {
        if ($timestamp === null || trim($timestamp) === '' || strpos($timestamp, '0000-00-00') === 0) {
            return null;
        }
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return null;
        }
        return self::tanggal_label(date('Y-m-d', $ts)) . ', ' . date('H:i:s', $ts) . ' WIB';
    }

    /** ISO-8601 with the WIB offset, for the API payload. */
    public static function waktu_iso(?string $timestamp): ?string
    {
        if ($timestamp === null || trim($timestamp) === '' || strpos($timestamp, '0000-00-00') === 0) {
            return null;
        }
        try {
            $dt = new DateTime($timestamp, new DateTimeZone(self::TIMEZONE));
            return $dt->format('c');
        } catch (Exception $e) {
            return null;
        }
    }

    /** "26 hari lalu" / "3 jam lalu" — relative age of the oldest data. */
    public static function usia_label(?string $timestamp, ?int $now = null): ?string
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return null;
        }
        $diff = max(0, ($now ?? time()) - $ts);
        if ($diff < 3600) {
            return max(1, intval(round($diff / 60))) . ' menit lalu';
        }
        if ($diff < 86400) {
            return intval(floor($diff / 3600)) . ' jam lalu';
        }
        return intval(floor($diff / 86400)) . ' hari lalu';
    }

    /** "16:05:24–22:24:04 WIB" — the observation window for a date. */
    public static function rentang_sinkronisasi(?string $terlama, ?string $terbaru): ?string
    {
        if ($terlama === null && $terbaru === null) {
            return null;
        }
        $a = $terlama !== null ? strtotime($terlama) : null;
        $b = $terbaru !== null ? strtotime($terbaru) : null;
        if ($a === null || $a === false) $a = $b;
        if ($b === null || $b === false) $b = $a;
        if ($a === null || $b === null || $a === false || $b === false) {
            return null;
        }
        if (date('H:i:s', $a) === date('H:i:s', $b)) {
            return date('H:i:s', $a) . ' WIB';
        }
        return date('H:i:s', $a) . '–' . date('H:i:s', $b) . ' WIB';
    }

    /** Indonesian label for a per-content daily state. */
    public static function status_label(string $state): string
    {
        $map = [
            self::STATE_BERHASIL => 'Berhasil',
            self::STATE_DATA_AWAL => 'Data awal',
            self::STATE_GAGAL => 'Gagal sinkronisasi',
            self::STATE_BELUM_PERNAH => 'Belum pernah berhasil disinkronkan',
        ];
        return $map[$state] ?? 'Gagal sinkronisasi';
    }

    /** Inclusive Y-m-d range, matching the legacy createRange() contract. */
    public static function date_range(string $from, string $until): array
    {
        if (!self::is_date($from) || !self::is_date($until) || $from > $until) {
            return [];
        }
        $out = [];
        $cursor = strtotime($from);
        $end = strtotime($until);
        while ($cursor <= $end) {
            $out[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }
        return $out;
    }

    private static function is_date(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    }

    /** Metric definitions shipped with the payload so the UI cannot drift. */
    public static function definisi_metrik(): array
    {
        return [
            'total_views_terakhir_disinkronkan' => 'Jumlah views kumulatif dari sinkronisasi terakhir yang berhasil untuk setiap konten dalam filter. Bukan permintaan langsung ke TikTok.',
            'kenaikan_views' => 'Selisih antara dua sinkronisasi berhasil berturut-turut per konten, dijumlahkan. Tidak pernah negatif; opening views tidak dihitung.',
            'opening_views' => 'Views yang sudah ada saat konten pertama kali berhasil disinkronkan. Bukan pertumbuhan pada hari tersebut.',
            'jumlah_menggunakan_data_terakhir' => 'Konten yang gagal disinkronkan pada tanggal tersebut sehingga memakai nilai terakhir yang berhasil.',
            'jumlah_belum_pernah_berhasil' => 'Konten yang belum pernah berhasil disinkronkan. Tidak dianggap sebagai konten dengan 0 views.',
        ];
    }

    /** Additive legacy spelling retained for diagnostics clients. */
    public static function metric_definitions(): array
    {
        return self::definisi_metrik();
    }

    // ----------------------------------------------------------------- filters

    /**
     * Build the SQL filter fragments for the V2 read model from request input.
     *
     * Pure so the PIC and date-range semantics are testable. Reproduces the
     * legacy contract documented in the Phase 1 audit:
     *
     *   - `pic[]`  is an EXACT multi-select   -> endorse.pic IN (...)
     *   - `keyword` with keyword_category=PIC is a SUBSTRING search -> LIKE
     *   - date ranges are INCLUSIVE at both ends
     *
     * @param array    $get    Request parameters.
     * @param callable $escape Value escaper, e.g. [$db, 'escape_str'].
     * @return array {where, from, until, needs_campaign_join, has_date_filter, pic_mode}
     */
    public static function build_filters(array $get, callable $escape): array
    {
        $q = function ($v) use ($escape) {
            return "'" . $escape(strval($v)) . "'";
        };

        $where = [];
        $needsCampaignJoin = false;
        $picMode = 'tidak_difilter';

        $rawFrom = strval($get['start_date'] ?? '');
        $rawUntil = strval($get['until_date'] ?? '');
        $hasDateFilter = self::is_date($rawFrom) || self::is_date($rawUntil);

        $from = self::is_date($rawFrom) ? $rawFrom : '';
        $until = self::is_date($rawUntil) ? $rawUntil : '';
        if ($from === '') {
            $from = date('Y-m-d', strtotime('-31 days'));
        }
        if ($until === '') {
            $until = date('Y-m-d');
        }
        // A reversed range would silently return nothing; normalise instead.
        if ($from > $until) {
            [$from, $until] = [$until, $from];
        }

        $campaignIds = self::int_list($get['ids_campaign'] ?? null);
        if (!empty($campaignIds)) {
            $where[] = 'e.id_campaign IN (' . implode(',', $campaignIds) . ')';
        } elseif (!empty($get['id_campaign'])) {
            $where[] = 'e.id_campaign = ' . intval($get['id_campaign']);
        }

        $ids = self::int_list($get['ids'] ?? null);
        if (!empty($ids)) {
            $where[] = 'l.id_endorse IN (' . implode(',', $ids) . ')';
        }

        // Exact multi-select PIC — matches the legacy `pic[]` contract.
        $pic = $get['pic'] ?? null;
        if (!empty($pic)) {
            if (!is_array($pic)) {
                $pic = [$pic];
            }
            $pic = array_values(array_filter($pic, function ($v) {
                return $v !== '' && $v !== null;
            }));
            if (!empty($pic)) {
                $where[] = 'e.pic IN (' . implode(',', array_map($q, $pic)) . ')';
                $picMode = 'tepat';
            }
        }

        $product = $get['product'] ?? null;
        if (!empty($product)) {
            if (!is_array($product)) {
                $product = [$product];
            }
            $product = array_values(array_filter($product, function ($v) {
                return $v !== '' && $v !== null;
            }));
            if (!empty($product)) {
                $where[] = 'e.product IN (' . implode(',', array_map($q, $product)) . ')';
            }
        }

        if (!empty($get['brand'])) {
            $where[] = 'e.brand = ' . $q($get['brand']);
        }
        if (!empty($get['platform'])) {
            $where[] = 'e.platform = ' . $q($get['platform']);
        }
        if (!empty($get['status_data'])) {
            $where[] = 'e.status = ' . $q($get['status_data']);
        }

        $category = $get['endorse_category'] ?? null;
        if ($category === 'internal') {
            $where[] = 'c.is_internal = 1';
            $needsCampaignJoin = true;
        } elseif ($category === 'external') {
            $where[] = 'c.is_internal = 0';
            $needsCampaignJoin = true;
        }

        $status = $get['status'] ?? null;
        if ($status === 'Ada Link Upload') {
            $where[] = "e.link_upload != ''";
        } elseif ($status === 'Tidak Ada Link Upload') {
            $where[] = "e.link_upload = ''";
        } elseif ($status === 'FYP') {
            $where[] = 'e.is_fyp = 1';
        }

        foreach (['endorse_status' => 'status_endorse', 'status_payment' => 'status_payment'] as $key => $col) {
            if (empty($get[$key])) {
                continue;
            }
            $vals = is_array($get[$key]) ? $get[$key] : explode(',', strval($get[$key]));
            $vals = array_values(array_filter($vals, function ($v) {
                return $v !== '' && $v !== null;
            }));
            if (!empty($vals)) {
                $where[] = 'e.' . $col . ' IN (' . implode(',', array_map($q, $vals)) . ')';
            }
        }

        // Substring keyword search — a deliberately different population from
        // the exact `pic[]` filter above. Both are preserved as-is.
        $keyword = strval($get['keyword'] ?? '');
        if ($keyword !== '') {
            $cols = [
                'Nama Creator' => 'e.nama_creator',
                'Link Upload' => 'e.link_upload',
                'PIC' => 'e.pic',
                'Platform' => 'e.platform',
                'Task' => 'e.task',
                'Keterangan' => 'e.`desc`',
            ];
            $cat = strval($get['keyword_category'] ?? 'Nama Creator');
            if (isset($cols[$cat])) {
                $where[] = $cols[$cat] . ' LIKE ' . $q('%' . $keyword . '%');
                if ($cat === 'PIC') {
                    $picMode = 'sebagian';
                }
            }
        }

        return [
            'where' => $where,
            'from' => $from,
            'until' => $until,
            'needs_campaign_join' => $needsCampaignJoin,
            'has_date_filter' => $hasDateFilter,
            'pic_mode' => $picMode,
        ];
    }

    /** Coerce a scalar or array of ids into a list of positive ints. */
    /** Coerce a comma-separated or array request field into unique positive IDs. */
    public static function int_list($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $parts = is_array($value) ? $value : explode(',', strval($value));
        $out = [];
        foreach ($parts as $p) {
            $n = intval($p);
            if ($n > 0) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }
}
