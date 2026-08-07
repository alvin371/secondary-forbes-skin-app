<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<style>
  .ea2-card { border:1px solid #d9e0e7; border-radius:10px; background:#fff; padding:14px; height:100%; }
  .ea2-label { color:#536273; font-size:12px; font-weight:600; }
  .ea2-value { color:#17212b; font-size:24px; font-weight:700; line-height:1.25; margin:6px 0; }
  .ea2-meta { color:#536273; font-size:12px; line-height:1.5; }
  .ea2-status { display:inline-block; padding:2px 7px; border-radius:999px; font-size:11px; font-weight:600; }
  .ea2-lengkap { background:#d9f3e5; color:#155c38; }.ea2-sebagian_lengkap { background:#fff0c7; color:#765400; }.ea2-tidak_memadai { background:#fbe0e1; color:#8b2730; }
  #ea2-chart-wrap { height:330px; position:relative; }
  .ea2-daily { border-top:1px solid #d9e0e7; padding:14px 0; }.ea2-daily:first-child { border-top:0; }
  .ea2-daily strong { color:#17212b; }.ea2-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:10px; }
  @media (max-width:767px) { #ea2-chart-wrap { height:260px; }.ea2-value { font-size:21px; } }
</style>

<div class="col-lg-12 mb-3" id="endorse-analytics-v2">
  <div class="card summary p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div><h3 class="text-primary fw-600 mb-0">Analitik Endorse</h3><small class="text-muted">Versi 2, berdasarkan sinkronisasi sistem</small></div>
      <div class="btn-group btn-group-sm" role="group" aria-label="Tampilan grafik"><button class="btn btn-outline-primary" data-ea2-mode="kenaikan">Kenaikan</button><button class="btn btn-outline-primary" data-ea2-mode="total">Total</button><button class="btn btn-primary active" data-ea2-mode="gabungan">Gabungan</button></div>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-sm-6 col-xl-3"><section class="ea2-card"><div class="ea2-label">Total Views Terakhir Disinkronkan</div><div id="ea2-total" class="ea2-value">—</div><div id="ea2-total-meta" class="ea2-meta"></div></section></div>
      <div class="col-sm-6 col-xl-3"><section class="ea2-card"><div class="ea2-label">Total Kenaikan Views</div><div id="ea2-growth" class="ea2-value">—</div><div id="ea2-growth-meta" class="ea2-meta"></div></section></div>
      <div class="col-sm-6 col-xl-3"><section class="ea2-card"><div class="ea2-label">Status Sinkronisasi</div><div id="ea2-status" class="ea2-value">—</div><div id="ea2-status-meta" class="ea2-meta"></div><a id="ea2-problems" class="small" href="#">Lihat Masalah</a></section></div>
      <div class="col-sm-6 col-xl-3"><section class="ea2-card"><div class="ea2-label">Duplikat Terdeteksi</div><div id="ea2-duplicates" class="ea2-value">—</div><div id="ea2-duplicates-meta" class="ea2-meta"></div><a id="ea2-duplicates-link" class="small" href="#">Lihat Data</a></section></div>
    </div>
    <div id="ea2-chart-wrap"><canvas id="ea2-chart"></canvas></div>
    <p class="ea2-meta mt-2 mb-0" id="ea2-note"></p>
  </div>
</div>
<div class="col-lg-12 mb-3"><div class="card summary p-3"><h4 class="text-primary fw-600 mb-2">Rincian Harian</h4><div id="ea2-daily"><div class="text-muted small">Memuat rincian sinkronisasi…</div></div></div></div>

<script>
(function () {
  var endpoint = '<?= base_url() ?>ajax/get-chart-campaign-v2', mode = 'gabungan', data = null;
  function angka(v, plus) { if (v === null || v === undefined) return '—'; return (plus && Number(v) > 0 ? '+' : '') + Number(v).toLocaleString('id-ID'); }
  function teksWaktu(v) { if (!v) return 'Belum ada data'; var d = new Date(v); return isNaN(d) ? v : d.toLocaleDateString('id-ID',{day:'numeric',month:'long',year:'numeric'}) + ', ' + d.toLocaleTimeString('id-ID',{hour12:false}) + ' WIB'; }
  function esc(v) { return $('<div>').text(v || '').html(); }
  function tautanMasalah() { var p = new URLSearchParams(location.search); p.set('status','Gagal Sinkronisasi'); return '<?= base_url() ?>endorse?' + p.toString(); }
  function renderCards(p) {
    var s = p.ringkasan || p.summary || {};
    $('#ea2-total').text(angka(s.total_views_terakhir_disinkronkan));
    $('#ea2-total-meta').html('Data terbaru: ' + teksWaktu(s.sinkronisasi_terbaru) + '<br>Data tertua: ' + teksWaktu(s.sinkronisasi_terlama) + '<br>' + angka(s.jumlah_berhasil) + ' dari ' + angka(s.jumlah_post) + ' post berhasil, cakupan ' + angka(s.cakupan_persen) + '%');
    $('#ea2-growth').text(angka(s.total_kenaikan_views, true));
    $('#ea2-growth-meta').text(s.menggunakan_filter_tanggal ? 'Akumulasi kenaikan pada periode yang dipilih.' : 'Kenaikan pada tanggal pelaporan terbaru.');
    $('#ea2-status').text(angka(s.jumlah_berhasil) + '/' + angka(s.jumlah_post) + ' berhasil');
    $('#ea2-status-meta').html(angka(s.jumlah_gagal) + ' gagal<br>' + angka(s.jumlah_belum_pernah_berhasil) + ' belum pernah berhasil<br>Terakhir sinkronisasi: ' + teksWaktu(s.sinkronisasi_terbaru));
    $('#ea2-duplicates').text(angka(s.jumlah_grup_duplikat) + ' grup');
    $('#ea2-duplicates-meta').text(angka(s.jumlah_baris_duplikat) + ' baris terindikasi duplikat');
    $('#ea2-problems').attr('href', tautanMasalah()); $('#ea2-duplicates-link').attr('href', '<?= base_url() ?>endorse?' + new URLSearchParams(location.search).toString());
    $('#ea2-note').text('Nilai total memakai snapshot berhasil terakhir per konten. Konten gagal memakai nilai terakhir dan ditandai sebagai data terbawa, bukan nol views.');
  }
  function renderDaily(p) {
    var days = p.harian || p.dates || [];
    if (!days.length) { $('#ea2-daily').html('<div class="text-muted small">Tidak ada data pada filter ini.</div>'); return; }
    $('#ea2-daily').html(days.slice().reverse().map(function(d) { var status = d.kelengkapan_data || 'tidak_memadai'; return '<article class="ea2-daily"><div class="d-flex justify-content-between gap-2"><strong>' + esc(d.tanggal_label || d.tanggal) + '</strong><span class="ea2-status ea2-' + esc(status) + '">' + esc({lengkap:'Lengkap',sebagian_lengkap:'Sebagian lengkap',tidak_memadai:'Tidak memadai'}[status] || status) + '</span></div><div class="ea2-grid mt-2"><div><small class="text-muted">Total Views</small><br><strong>' + angka(d.total_views_terakhir_disinkronkan) + '</strong></div><div><small class="text-muted">Kenaikan Hari Ini</small><br><strong>' + angka(d.kenaikan_views, true) + '</strong></div><div><small class="text-muted">Sinkronisasi</small><br><span class="small">' + teksWaktu(d.sinkronisasi_terbaru) + '</span></div></div><div class="ea2-meta mt-2">' + angka(d.jumlah_berhasil) + ' dari ' + angka(d.jumlah_post) + ' post berhasil, cakupan ' + angka(d.cakupan_persen) + '% · ' + angka(d.jumlah_gagal) + ' post gagal · ' + angka(d.jumlah_menggunakan_data_terakhir) + ' memakai data terakhir · ' + angka(d.jumlah_grup_duplikat) + ' grup duplikat' + (d.memiliki_anomali ? ' · Nilai total menurun dan perlu diperiksa' : '') + '</div><a class="small" href="' + tautanMasalah() + '">Lihat Detail</a></article>'; }).join(''));
  }
  function renderChart(p) {
    var canvas = document.getElementById('ea2-chart'), days = p.harian || p.dates || [], old = Chart.getChart(canvas); if (old) old.destroy();
    var all = [{type:'bar',label:'Kenaikan Views Harian',data:days.map(function(d){return d.kenaikan_views;}),yAxisID:'growth',backgroundColor:'rgba(13,110,253,.55)',borderColor:'#0d6efd',borderWidth:1},{type:'line',label:'Total Views Terakhir Disinkronkan',data:days.map(function(d){return d.total_views_terakhir_disinkronkan;}),yAxisID:'total',borderColor:'#157347',backgroundColor:'rgba(21,115,71,.12)',borderWidth:2,pointRadius:2,tension:.2}];
    var datasets = mode === 'kenaikan' ? [all[0]] : mode === 'total' ? [all[1]] : all;
    new Chart(canvas,{type:'bar',data:{labels:days.map(function(d){return d.tanggal_label || d.tanggal;}),datasets:datasets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{growth:{display:mode !== 'total',position:'left',beginAtZero:true,title:{display:mode !== 'total',text:'Kenaikan Views'}},total:{display:mode !== 'kenaikan',position:mode === 'gabungan'?'right':'left',beginAtZero:true,grid:{drawOnChartArea:mode !== 'gabungan'},title:{display:mode !== 'kenaikan',text:'Total Views'}}},plugins:{tooltip:{callbacks:{afterBody:function(items){var d=days[items[0].dataIndex];return ['Total views terakhir disinkronkan: '+angka(d.total_views_terakhir_disinkronkan),'Kenaikan views: '+angka(d.kenaikan_views,true),angka(d.jumlah_berhasil)+' dari '+angka(d.jumlah_post)+' post berhasil disinkronkan',angka(d.jumlah_gagal)+' post gagal',angka(d.jumlah_menggunakan_data_terakhir)+' post memakai data terakhir',angka(d.jumlah_grup_duplikat)+' grup duplikat','Sinkronisasi terakhir: '+teksWaktu(d.sinkronisasi_terbaru),'Status data: '+({lengkap:'Lengkap',sebagian_lengkap:'Sebagian lengkap',tidak_memadai:'Tidak memadai'}[d.kelengkapan_data] || d.kelengkapan_data)];}}}}}});
  }
  function render() { if (!data) return; renderCards(data); renderDaily(data); renderChart(data); }
  window.getChartV2 = function(){ var p=new URLSearchParams(location.search); p.set('start_date',$('#chart_start_date').val() || p.get('start_date') || ''); p.set('until_date',$('#chart_until_date').val() || p.get('until_date') || ''); $.getJSON(endpoint + '?' + p.toString()).done(function(v){if(v && v.enabled !== false){data=v;render();}}).fail(function(){ $('#ea2-note').text('Gagal memuat Analitik Endorse.'); }); };
  $(document).on('click','[data-ea2-mode]',function(){ mode=$(this).data('ea2-mode'); $('[data-ea2-mode]').removeClass('btn-primary active').addClass('btn-outline-primary'); $(this).removeClass('btn-outline-primary').addClass('btn-primary active'); renderChart(data || {}); });
  $(function(){ window.getChartV2(); });
})();
</script>
