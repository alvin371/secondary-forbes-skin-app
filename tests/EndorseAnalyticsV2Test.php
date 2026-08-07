<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
defined('BASEPATH') || define('BASEPATH', __DIR__);
require_once __DIR__ . '/../application/libraries/Endorse_analytics_v2.php';

/** Product-rule tests for the pure, read-only Analytics V2 metric core. */
final class EndorseAnalyticsV2Test extends TestCase
{
    private function series(array $observations, array $dates, ?string $entered = '2026-08-03', ?int $prior = null, bool $before = false): array
    { return Endorse_analytics_v2::build_content_series($observations, $dates, $entered, $prior, $before); }

    public function test_first_sync_is_opening_not_growth(): void
    { $d=$this->series(['2026-08-03'=>15000],['2026-08-03'])['2026-08-03']; self::assertSame(15000,$d['total']); self::assertSame(15000,$d['opening']); self::assertSame(0,$d['kenaikan']); self::assertSame('data_awal',$d['state']); }

    public function test_consecutive_and_unchanged_syncs(): void
    { $s=$this->series(['2026-08-03'=>15000,'2026-08-04'=>20000,'2026-08-05'=>20000],['2026-08-03','2026-08-04','2026-08-05']); self::assertSame(5000,$s['2026-08-04']['kenaikan']); self::assertSame(0,$s['2026-08-05']['kenaikan']); }

    public function test_failure_carries_value_and_recovers(): void
    { $s=$this->series(['2026-08-03'=>15000,'2026-08-04'=>20000,'2026-08-06'=>21500],['2026-08-03','2026-08-04','2026-08-05','2026-08-06']); self::assertSame('gagal',$s['2026-08-05']['state']); self::assertTrue($s['2026-08-05']['carried']); self::assertSame(20000,$s['2026-08-05']['total']); self::assertSame(0,$s['2026-08-05']['kenaikan']); self::assertSame(1500,$s['2026-08-06']['kenaikan']); }

    public function test_negative_difference_is_visible_anomaly(): void
    { $s=$this->series(['2026-08-03'=>20000,'2026-08-04'=>15000],['2026-08-03','2026-08-04']); self::assertSame(0,$s['2026-08-04']['kenaikan']); self::assertTrue($s['2026-08-04']['anomali']); self::assertSame(-5000,$s['2026-08-04']['selisih_mentah']); }

    public function test_unresolved_post_is_not_a_trustworthy_zero(): void
    { $d=$this->series([],['2026-08-03'])['2026-08-03']; self::assertSame('belum_pernah_berhasil',$d['state']); self::assertSame(0,$d['total']); self::assertSame(0,$d['kenaikan']); }

    public function test_aggregate_calculates_per_post_before_summing(): void
    { $dates=['2026-08-03','2026-08-04']; $a=$this->series(['2026-08-03'=>100,'2026-08-04'=>200],$dates); $b=$this->series(['2026-08-04'=>5000],$dates,'2026-08-04'); $buckets=Endorse_analytics_v2::aggregate_dates(['a'=>$a,'b'=>$b],$dates); self::assertSame(5200,$buckets['2026-08-04']['total_views_terakhir_disinkronkan']); self::assertSame(100,$buckets['2026-08-04']['kenaikan_views']); }

    public function test_aggregate_exposes_failure_coverage_and_anomaly(): void
    { $dates=['2026-08-03','2026-08-04']; $a=$this->series(['2026-08-03'=>100,'2026-08-04'=>50],$dates); $b=$this->series(['2026-08-03'=>100],$dates); $x=Endorse_analytics_v2::aggregate_dates(['a'=>$a,'b'=>$b],$dates); $d=$x['2026-08-04']; self::assertSame(1,$d['jumlah_berhasil']); self::assertSame(1,$d['jumlah_gagal']); self::assertSame(1,$d['jumlah_menggunakan_data_terakhir']); self::assertSame(1,$d['jumlah_anomali']); self::assertSame(50,$d['cakupan_persen']); }

    public function test_summary_uses_end_date_and_range_growth(): void
    { $dates=['2026-08-03','2026-08-04']; $a=$this->series(['2026-08-03'=>100,'2026-08-04'=>200],$dates); $b=Endorse_analytics_v2::aggregate_dates(['a'=>$a],$dates,['sync_times'=>['2026-08-04'=>['terbaru'=>'2026-08-04 22:24:04','terlama'=>'2026-08-04 16:05:24']]]); $filtered=Endorse_analytics_v2::summarize(array_values($b),true); $unfiltered=Endorse_analytics_v2::summarize(array_values($b),false); self::assertSame(200,$filtered['total_views_terakhir_disinkronkan']); self::assertSame(100,$filtered['total_kenaikan_views']); self::assertSame(100,$unfiltered['total_kenaikan_views']); self::assertSame('4 Agustus 2026, 22:24:04 WIB',Endorse_analytics_v2::waktu_label($filtered['sinkronisasi_terbaru'])); }

    public function test_indonesian_labels_and_failure_reasons(): void
    { self::assertSame('Penyedia data sedang bermasalah',Endorse_analytics_v2::alasan_gagal('transient')); self::assertSame('Sebagian lengkap',Endorse_analytics_v2::kelengkapan_label('sebagian_lengkap')); self::assertStringContainsString('Bukan permintaan langsung',Endorse_analytics_v2::definisi_metrik()['total_views_terakhir_disinkronkan']); }

    public function test_core_never_contains_database_writes(): void
    { $src=file_get_contents(__DIR__.'/../application/libraries/Endorse_analytics_v2.php'); self::assertStringNotContainsString('UPDATE endorse_logs',$src); self::assertStringNotContainsString('INSERT INTO endorse_logs',$src); self::assertStringNotContainsString('DELETE FROM endorse_logs',$src); }
}
