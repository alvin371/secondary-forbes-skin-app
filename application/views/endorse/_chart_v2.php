<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="col-lg-12 mb-3" id="endorse-analytics-v2">
  <div class="card summary p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <div><h3 class="text-primary fw-600 mb-0">Grafik Views Postingan</h3><small class="text-muted">Agregasi dari postingan yang sesuai filter</small></div>
      <div class="btn-group btn-group-sm" role="group" aria-label="Tampilan grafik"><button class="btn btn-outline-primary" data-ea2-mode="kenaikan">Kenaikan</button><button class="btn btn-outline-primary" data-ea2-mode="total">Total</button><button class="btn btn-primary active" data-ea2-mode="gabungan">Gabungan</button></div>
    </div>
    <div style="height:330px"><canvas id="ea2-chart"></canvas></div>
    <p class="small text-muted mt-2 mb-0" id="ea2-note"></p>
  </div>
</div>
<script>
(function () {
  var mode='gabungan', data=null, endpoint='<?= base_url() ?>ajax/get-chart-campaign-v2';
  function angka(v, plus){return (plus && Number(v)>0?'+':'')+Number(v||0).toLocaleString('id-ID');}
  function render(){if(!data)return;var days=data.harian||[],canvas=document.getElementById('ea2-chart'),old=Chart.getChart(canvas);if(old)old.destroy();var all=[{type:'bar',label:'Kenaikan Views Harian',data:days.map(function(d){return d.kenaikan_views;}),yAxisID:'growth',backgroundColor:'rgba(13,110,253,.55)',borderColor:'#0d6efd',borderWidth:1},{type:'line',label:'Total Views Terakhir Disinkronkan',data:days.map(function(d){return d.total_views_terakhir_disinkronkan;}),yAxisID:'total',borderColor:'#157347',borderWidth:2,pointRadius:2,tension:.2}];var sets=mode==='kenaikan'?[all[0]]:mode==='total'?[all[1]]:all;new Chart(canvas,{type:'bar',data:{labels:days.map(function(d){return d.tanggal_label||d.tanggal;}),datasets:sets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{growth:{display:mode!=='total',position:'left',beginAtZero:true,title:{display:true,text:'Kenaikan Views'}},total:{display:mode!=='kenaikan',position:mode==='gabungan'?'right':'left',beginAtZero:true,grid:{drawOnChartArea:mode!=='gabungan'},title:{display:true,text:'Total Views'}}},plugins:{tooltip:{callbacks:{afterBody:function(items){var d=days[items[0].dataIndex];return ['Total views terakhir disinkronkan: '+angka(d.total_views_terakhir_disinkronkan),'Kenaikan views: '+angka(d.kenaikan_views,true),angka(d.jumlah_berhasil)+' dari '+angka(d.jumlah_post)+' post berhasil',angka(d.jumlah_gagal)+' post gagal',angka(d.jumlah_menggunakan_data_terakhir)+' memakai data terakhir',angka(d.jumlah_grup_duplikat)+' grup duplikat'];}}}}}});}
  window.getChartV2=function(){var p=new URLSearchParams(location.search);p.set('start_date',$('#chart_start_date').val()||p.get('chart_start_date')||p.get('start_date')||'');p.set('until_date',$('#chart_until_date').val()||p.get('chart_until_date')||p.get('until_date')||'');$.getJSON(endpoint+'?'+p.toString()).done(function(v){if(v&&v.enabled!==false){data=v;render();$('#ea2-note').text('Grafik menjumlahkan total dan kenaikan dari card postingan yang sesuai filter.');}}).fail(function(){ $('#ea2-note').text('Gagal memuat grafik postingan.'); });};
  $(document).on('click','[data-ea2-mode]',function(){mode=$(this).data('ea2-mode');$('[data-ea2-mode]').removeClass('btn-primary active').addClass('btn-outline-primary');$(this).removeClass('btn-outline-primary').addClass('btn-primary active');render();});$(window.getChartV2);
})();
</script>
