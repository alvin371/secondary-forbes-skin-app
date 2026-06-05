<?php
$id_campaign = $detail['id'];
$start_date  = isset($start_date) ? $start_date : date('Y-m-01');
$until_date  = isset($until_date) ? $until_date : date('Y-m-d');
$base        = base_url();
?>
<style>
.btn-xs { padding: 0 .25rem; font-size: .75rem; line-height: 1.4; border-radius: .2rem; }
.rank-gold   { color: #f5c518; font-size: 16px; }
.rank-silver { color: #adb5bd; font-size: 16px; }
.rank-bronze { color: #cd7f32; font-size: 16px; }
.col-hint { cursor: help; text-decoration: underline dotted; }
.fs-10 { font-size: 10px; }
.fs-11 { font-size: 11px; }
.fs-14 { font-size: 14px; }
.fs-24 { font-size: 24px; }
.an-filter-bar { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: .375rem; }
@media (min-width: 768px) {
  .an-kpi-strip > div:not(:last-child) { border-right: 1px solid #dee2e6; }
}
@media (max-width: 767.98px) {
  .an-kpi-strip > div:nth-child(1) { border-bottom: 1px solid #dee2e6; border-right: 1px solid #dee2e6; }
  .an-kpi-strip > div:nth-child(2) { border-bottom: 1px solid #dee2e6; }
}
.an-tab-toolbar { border-bottom: 1px solid #dee2e6; background: #f8f9fa; border-radius: .375rem .375rem 0 0; }
.an-tab-toolbar .form-select,
.an-tab-toolbar .btn,
.an-filter-bar .btn { height: 45px; }
.an-tab-toolbar .input-group-text { height: 45px; }
.tab-pane .table-responsive { margin-top: 2px; }
th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
th.sortable::after { content: ' \2195'; font-size: 11px; opacity: 0.45; margin-left: 2px; }
th.sort-asc::after  { content: ' \2191'; opacity: 1; color: var(--bs-primary); }
th.sort-desc::after { content: ' \2193'; opacity: 1; color: var(--bs-primary); }
th.sort-asc, th.sort-desc { background: rgba(var(--bs-primary-rgb), 0.06); }
</style>

<!-- Shared Detail Modal -->
<div class="modal fade" id="an-detail-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div>
                    <h6 class="modal-title fw-600 mb-0" id="an-detail-modal-title">Detail Scraping</h6>
                    <div id="an-detail-modal-sub" class="fs-11 text-muted"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="an-detail-modal-body"></div>
        </div>
    </div>
</div>

<div class="w-100">
    <div class="row">
        <div class="col-lg-12 mb-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <a href="<?= $base ?>endorse?id_campaign=<?= $id_campaign ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Kembali
                </a>
                <h3 class="fw-600 mb-0 lh-1" style="font-size:1.2rem"><?= htmlspecialchars($detail['title']) ?> <span class="fw-400 text-muted fs-14">— Analytics</span></h3>
            </div>
        </div>

        <!-- Date range filter -->
        <div class="col-lg-12 mb-3">
            <div class="an-filter-bar d-flex flex-wrap align-items-end gap-3 px-3 py-2">
                <div>
                    <label class="fs-11 text-muted mb-1 d-block">Tanggal Mulai</label>
                    <input type="date" id="an-start" class="form-control" value="<?= $start_date ?>">
                </div>
                <div>
                    <label class="fs-11 text-muted mb-1 d-block">Tanggal Akhir</label>
                    <input type="date" id="an-until" class="form-control" value="<?= $until_date ?>">
                </div>
                <div>
                    <label class="fs-11 text-muted mb-1 d-block">&nbsp;</label>
                    <button class="btn btn-primary" onclick="loadAll()"><i class="bi bi-search"></i> Terapkan</button>
                </div>
            </div>
        </div>

        <!-- KPI strip -->
        <div class="col-lg-12 mb-4">
            <div class="card">
                <div class="row g-0 text-center an-kpi-strip">
                    <div class="col-md-4 col-6 py-3 px-2">
                        <div class="fs-10 fw-600 text-muted text-uppercase mb-1" style="letter-spacing:.04em">Missing Data</div>
                        <div id="an-kpi-missing" class="fw-700 fs-24">-</div>
                        <div class="fs-11 text-muted">creator ≥ 2 hari tanpa log</div>
                    </div>
                    <div class="col-md-4 col-6 py-3 px-2">
                        <div class="fs-10 fw-600 text-muted text-uppercase mb-1" style="letter-spacing:.04em">Top Performer</div>
                        <div id="an-kpi-top" class="fw-600 fs-14 text-truncate px-2">-</div>
                        <div id="an-kpi-top-views" class="fs-11 text-success">-</div>
                    </div>
                    <div class="col-md-4 col-6 py-3 px-2">
                        <div class="fs-10 fw-600 text-muted text-uppercase mb-1" style="letter-spacing:.04em">Avg Views/Hari</div>
                        <div id="an-kpi-avg" class="fw-700 fs-24">-</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="col-lg-12">
            <div class="card">
                <ul class="nav nav-tabs mb-0 border-bottom" id="an-tabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#tab-missing">
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i> Missing
                            <span class="badge bg-danger ms-1" id="badge-missing">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tab-performers">
                            <i class="bi bi-trophy-fill text-warning"></i> Performers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tab-trends">
                            <i class="bi bi-graph-up text-primary"></i> Trends
                        </a>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- ===================== TAB 1: MISSING DATA ===================== -->
                    <div class="tab-pane fade show active" id="tab-missing">
                        <div class="an-tab-toolbar d-flex flex-wrap gap-2 align-items-center px-3 pt-3 pb-2 mb-0">
                            <input type="text" id="search-missing" class="form-control" style="max-width:200px" placeholder="Cari creator...">
                            <select id="filter-missing-platform" class="form-select" style="max-width:140px">
                                <option value="">Semua Platform</option>
                                <option>Tiktok</option><option>Instagram</option>
                                <option>Youtube</option><option>Facebook</option>
                            </select>
                            <div class="input-group" style="max-width:280px">
                                <span class="input-group-text fs-11">Threshold</span>
                                <input type="number" id="missing-threshold" value="2" min="1" max="30" class="form-control" style="width:60px" title="Hari tanpa log scraping">
                                <span class="input-group-text fs-11">hari</span>
                                <button class="btn btn-primary" onclick="loadMissing()">Terapkan</button>
                            </div>
                            <span class="ms-auto fs-12 text-muted" id="count-missing"></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0" id="tbl-missing">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:36px">#</th>
                                        <th>Creator</th>
                                        <th>Platform</th>
                                        <th>Status</th>
                                        <th>Terakhir Log</th>
                                        <th class="sortable sort-desc" data-sort="days">Hari Tanpa Log</th>
                                        <th>Tgl Posting</th>
                                        <th>Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-missing">
                                    <tr><td colspan="8" class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary" role="status" aria-label="Memuat..."></span></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ===================== TAB 2: PERFORMERS ===================== -->
                    <div class="tab-pane fade" id="tab-performers">
                        <div class="an-tab-toolbar d-flex flex-wrap gap-2 align-items-center px-3 pt-3 pb-2 mb-0">
                            <input type="text" id="search-perf" class="form-control" style="max-width:200px" placeholder="Cari creator...">
                            <select id="filter-perf-platform" class="form-select" style="max-width:140px">
                                <option value="">Semua Platform</option>
                                <option>Tiktok</option><option>Instagram</option>
                                <option>Youtube</option><option>Facebook</option>
                            </select>
                            <select id="perf-sort" class="d-none">
                                <option value="views">Sort: Views</option>
                                <option value="engagement">Sort: Engagement</option>
                                <option value="cpm">Sort: CPM</option>
                            </select>
                            <select id="perf-order" class="d-none">
                                <option value="desc">Tertinggi</option>
                                <option value="asc">Terendah</option>
                            </select>
                            <select id="perf-limit" class="form-select" style="max-width:120px" onchange="renderPerformers()">
                                <option value="10">Top 10</option>
                                <option value="bottom10">Bottom 10</option>
                                <option value="all">Semua</option>
                            </select>
                            <span class="ms-auto fs-11 text-muted" id="count-perf"></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0" id="tbl-performers">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px">Rank</th>
                                        <th>Creator</th>
                                        <th>Platform</th>
                                        <th>Link</th>
                                        <th class="text-end sortable" data-sort="views">Views Gain</th>
                                        <th class="text-end sortable" data-sort="engagement">Engagement</th>
                                        <th class="text-end sortable" data-sort="cpm">CPM</th>
                                        <th class="text-end">Cost</th>
                                        <th class="text-center">Detail</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-performers">
                                    <tr><td colspan="9" class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary" role="status" aria-label="Memuat..."></span></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ===================== TAB 3: TRENDS ===================== -->
                    <div class="tab-pane fade" id="tab-trends">
                        <div class="an-tab-toolbar d-flex flex-wrap gap-2 align-items-center px-3 pt-3 pb-2 mb-0">
                            <input type="text" id="search-trends" class="form-control" style="max-width:200px" placeholder="Cari creator / URL...">
                            <select id="filter-trends-platform" class="form-select" style="max-width:140px">
                                <option value="">Semua Platform</option>
                                <option>Tiktok</option><option>Instagram</option>
                                <option>Youtube</option><option>Facebook</option>
                            </select>
                            <select id="trends-sort" class="d-none">
                                <option value="views_desc">Views ↓</option>
                                <option value="views_asc">Views ↑</option>
                                <option value="name_asc">Nama A→Z</option>
                                <option value="name_desc">Nama Z→A</option>
                            </select>
                            <span class="ms-auto fs-12 text-muted" id="count-trends"></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 align-middle" id="tbl-trends">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:36px">#</th>
                                        <th class="sortable" data-sort="name">Creator</th>
                                        <th>Platform</th>
                                        <th>Status</th>
                                        <th style="min-width:240px">Trend Views Harian</th>
                                        <th class="text-end sortable" data-sort="views">Total Views</th>
                                        <th>Tgl Posting</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-trends">
                                    <tr><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary" role="status" aria-label="Memuat..."></span></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>



                </div>
            </div>
        </div>
    </div>
</div>

<script>
var AN_BASE     = '<?= $base ?>';
var AN_CAMPAIGN = '<?= $id_campaign ?>';
var _trendsData     = [];
var _performersData = [];
var _missingData    = [];
var _trendCharts    = [];
var _loadingCount   = 0;
var _missingSortOrder = 'desc';

function _setLoading(delta) {
    _loadingCount += delta;
    var btn = document.querySelector('button[onclick="loadAll()"]');
    if (btn) btn.disabled = _loadingCount > 0;
}

// ---- Formatters ----
function numFmt(n) {
    n = parseFloat(n);
    if (isNaN(n)) return '-';
    if (Math.abs(n) >= 1000000) return (n/1000000).toFixed(1) + 'M';
    if (Math.abs(n) >= 1000)    return (n/1000).toFixed(1) + 'K';
    return Math.round(n).toLocaleString('id-ID');
}
function numColor(n) {
    n = parseFloat(n);
    if (isNaN(n)) return '';
    return n < 0 ? ' text-danger' : (n > 0 ? ' text-success' : '');
}
function htmlEsc(s) { return $('<div>').text(s || '').html(); }
function htmlAttrEsc(s) { return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function platformIcon(p) {
    var icons = {
        'Tiktok':'<?= $base ?>assets/img/icon/icon-tiktok.png',
        'Instagram':'<?= $base ?>assets/img/icon/icon-instagram.png',
        'Youtube':'<?= $base ?>assets/img/icon/icon-youtube.png',
        'Facebook':'<?= $base ?>assets/img/icon/icon-facebook.png',
    };
    var src = icons[p];
    return src ? '<img src="'+src+'" alt="'+htmlEsc(p)+'" style="width:15px;height:15px" class="me-1" title="'+htmlEsc(p)+'">' : htmlEsc(p||'-');
}
function linkIcon(url) {
    if (!url) return '<span class="text-muted">-</span>';
    if (!/^https?:\/\//i.test(url)) return '<span class="text-muted fs-11">'+htmlEsc(url)+'</span>';
    var disp = url.replace(/^https?:\/\/(www\.)?/, '').split('/').filter(Boolean).pop() || url;
    if (disp.length > 22) disp = disp.substring(0, 22) + '…';
    return '<a href="'+htmlEsc(url)+'" target="_blank" rel="noopener noreferrer" class="text-decoration-none fs-11" title="'+htmlEsc(url)+'"><i class="bi bi-box-arrow-up-right me-1"></i>'+htmlEsc(disp)+'</a>';
}
function statusBadge(s) {
    if (!s) return '-';
    var cls = { 'Posted Content':'bg-success', 'Acc':'bg-primary', 'Review':'bg-info text-dark',
                'Hold':'bg-secondary', 'Reject':'bg-danger', 'Problem':'bg-danger',
                'Draft Content':'bg-warning text-dark' };
    var c = cls[s] || 'bg-light text-dark';
    return '<span class="badge '+c+' fs-10">'+htmlEsc(s)+'</span>';
}
function gainCell(n) {
    var cls = numColor(n);
    var sign = n > 0 ? '+' : '';
    return '<td class="text-end'+cls+'">'+sign+numFmt(n)+'</td>';
}
function anStart() { return $('#an-start').val(); }
function anUntil() { return $('#an-until').val(); }

// ---- Detail Modal ----
function showDetailModal(title, subtitle, daily_detail) {
    $('#an-detail-modal-title').text(title || 'Detail Scraping');
    $('#an-detail-modal-sub').html(subtitle ? htmlEsc(subtitle) : '');
    var html = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">';
    html += '<thead class="table-light"><tr>'
          + '<th>Tanggal</th>'
          + '<th class="text-end">Views Before</th>'
          + '<th class="text-end">Views After</th>'
          + '<th class="text-end">Views +/-</th>'
          + '<th class="text-end">Likes +/-</th>'
          + '<th class="text-end">Comment +/-</th>'
          + '<th class="text-end">Share/Save +/-</th>'
          + '</tr></thead><tbody>';
    if (!daily_detail || !daily_detail.length) {
        html += '<tr><td colspan="7" class="text-center text-muted">Tidak ada data</td></tr>';
    } else {
        $.each(daily_detail, function(i, d) {
            var vg = parseInt(d.views_gain);
            var lg = parseInt(d.likes_gain);
            var cg = parseInt(d.comment_gain);
            var sg = parseInt(d.share_save_gain);
            html += '<tr>';
            html += '<td>'+htmlEsc(d.date)+'</td>';
            html += '<td class="text-end">'+numFmt(d.views_before)+'</td>';
            html += '<td class="text-end">'+numFmt(d.views_after)+'</td>';
            html += '<td class="text-end'+(vg<0?' text-danger':vg>0?' text-success':'')+'"><b>'+(vg>0?'+':'')+numFmt(vg)+'</b></td>';
            html += '<td class="text-end'+(lg<0?' text-danger':lg>0?' text-success':'')+'">'+( lg>0?'+':'')+numFmt(lg)+'</td>';
            html += '<td class="text-end'+(cg<0?' text-danger':cg>0?' text-success':'')+'">'+( cg>0?'+':'')+numFmt(cg)+'</td>';
            html += '<td class="text-end'+(sg<0?' text-danger':sg>0?' text-success':'')+'">'+( sg>0?'+':'')+numFmt(sg)+'</td>';
            html += '</tr>';
        });
    }
    html += '</tbody></table></div>';
    $('#an-detail-modal-body').html(html);
    var modal = new bootstrap.Modal(document.getElementById('an-detail-modal'));
    modal.show();
}

// ---- Client-side filter helper ----
function applyRowFilter(tbodyId, searchVal, platformVal, reasonVal) {
    var s = (searchVal||'').toLowerCase();
    var p = (platformVal||'').toLowerCase();
    var r = (reasonVal||'').toLowerCase();
    var visible = 0;
    $('#'+tbodyId+' tr[data-searchable]').each(function() {
        var text  = $(this).attr('data-searchable').toLowerCase();
        var plat  = $(this).attr('data-platform')||'';
        var rsn   = $(this).attr('data-reason')||'';
        var show  = (!s || text.indexOf(s) !== -1)
                 && (!p || plat.toLowerCase() === p)
                 && (!r || rsn.toLowerCase().indexOf(r) !== -1);
        $(this).toggle(show);
        if (show) visible++;
    });
    return visible;
}

// ---- SUMMARY ----
function loadSummary() {
    _setLoading(1);
    $.getJSON(AN_BASE+'ajax/analytics-summary?id_campaign='+AN_CAMPAIGN+'&start_date='+anStart()+'&until_date='+anUntil(), function(d) {
        var mc = d.missing_count;
        if (mc > 0) {
            $('#an-kpi-missing').html('<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>'+mc+'</span>');
        } else {
            $('#an-kpi-missing').html('<span class="text-success">0</span>');
        }
        $('#an-kpi-top').text(d.top_creator||'-');
        var _topViewsClass = (d.top_creator_views > 0) ? 'text-success' : 'text-muted';
        $('#an-kpi-top-views').removeClass('text-success text-muted text-danger').addClass(_topViewsClass)
            .text(d.top_creator_views > 0 ? numFmt(d.top_creator_views)+' views' : '-');
        $('#an-kpi-avg').text(numFmt(d.avg_daily_views));
        $('#badge-missing').text(mc);
    }).fail(function() {
        $('#an-kpi-missing, #an-kpi-top, #an-kpi-top-views, #an-kpi-avg')
            .html('<span class="text-muted" title="Gagal memuat"><i class="bi bi-dash"></i></span>');
        if (!$('#kpi-error-banner').length) {
            $('.an-kpi-strip').closest('.card').prepend(
                '<div id="kpi-error-banner" class="alert alert-warning alert-dismissible py-2 px-3 mb-0 rounded-0 rounded-top" role="alert">'
                + '<i class="bi bi-wifi-off me-1"></i>Gagal memuat ringkasan. Periksa koneksi dan coba lagi.'
                + '<button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Tutup"></button>'
                + '</div>'
            );
        }
    }).always(function() { _setLoading(-1); });
}

// ---- MISSING DATA ----
function loadMissing() {
    var thr = $('#missing-threshold').val()||2;
    _setLoading(1);
    $('#tbody-missing').html('<tr><td colspan="8" class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary" role="status" aria-label="Memuat..."></span></td></tr>');
    $.getJSON(AN_BASE+'ajax/missing-creators?id_campaign='+AN_CAMPAIGN+'&threshold_days='+thr, function(data) {
        _missingData = data;
        renderMissing();
    }).fail(function() {
        $('#tbody-missing').html('<tr><td colspan="8" class="text-center text-danger py-3"><i class="bi bi-exclamation-circle me-1"></i>Gagal memuat. <button class="btn btn-sm btn-link p-0 text-danger" onclick="loadMissing()">Coba lagi</button></td></tr>');
    }).always(function() { _setLoading(-1); });
}
function renderMissing() {
    var data = _missingData.slice().sort(function(a, b) {
        var da = (a.days_since_log == null) ? 9999 : parseInt(a.days_since_log);
        var db = (b.days_since_log == null) ? 9999 : parseInt(b.days_since_log);
        return _missingSortOrder === 'desc' ? db - da : da - db;
    });
    if (!data.length) {
        $('#tbody-missing').html('<tr><td colspan="8" class="text-center text-success py-3"><i class="bi bi-check-circle"></i> Semua creator sudah log data</td></tr>');
        $('#count-missing').text('');
        return;
    }
    var thr = parseInt($('#missing-threshold').val()||2);
    var html = '';
    $.each(data, function(i, r) {
        var days   = (r.days_since_log===null||r.days_since_log===undefined) ? null : parseInt(r.days_since_log);
        var daysTxt, badge, keterangan;
        if (days === null) {
            daysTxt = '<span class="text-danger fw-600">Belum pernah</span>';
            badge = '<span class="badge bg-danger ms-1 fs-10">Kritis</span>';
            keterangan = '<span class="text-danger fs-11"><i class="bi bi-exclamation-circle me-1"></i>Belum pernah di-scraping sejak ditambahkan ke kampanye</span>';
        } else if (days > 3) {
            daysTxt = days + ' hari';
            badge = '<span class="badge bg-danger ms-1 fs-10">Kritis</span>';
            keterangan = '<span class="text-muted fs-11">Sudah '+days+' hari tidak ada data scraping baru</span>';
        } else {
            daysTxt = days + ' hari';
            badge = '<span class="badge bg-warning text-dark ms-1 fs-10">Waspada</span>';
            keterangan = '<span class="text-muted fs-11">Sudah '+days+' hari tidak ada data scraping baru</span>';
        }
        if (!r.posting_at && days !== null) {
            keterangan += ' <span class="text-warning fs-11">· Belum ada tanggal posting</span>';
        }
        var rowCls = (days===null||days>3) ? 'table-danger' : (days>=2 ? 'table-warning' : '');
        var searchable = (r.nama_creator||'') + ' ' + (r.platform||'') + ' ' + (r.link_upload||'');
        html += '<tr class="'+rowCls+'" data-searchable="'+htmlEsc(searchable)+'" data-platform="'+htmlEsc(r.platform||'')+'">';
        html += '<td class="text-muted fs-11">'+(i+1)+'</td>';
        html += '<td><div class="fw-500 text-truncate" style="max-width:180px">'+htmlEsc(r.nama_creator)+'</div>'
              + (r.link_upload ? '<div class="fs-10 text-muted">'+linkIcon(r.link_upload)+'</div>' : '')+'</td>';
        html += '<td>'+platformIcon(r.platform)+'</td>';
        html += '<td>'+statusBadge(r.status_endorse)+'</td>';
        html += '<td class="fs-12">'+(r.last_log_date||'<em class="text-danger fs-11">Belum ada log</em>')+'</td>';
        html += '<td>'+daysTxt+badge+'</td>';
        html += '<td class="fs-12">'+(r.posting_at||'<em class="text-muted fs-11">Belum diset</em>')+'</td>';
        html += '<td class="fs-11">'+keterangan+'</td>';
        html += '</tr>';
    });
    $('#tbody-missing').html(html);
    $('#count-missing').text(data.length + ' creator');
    $('#badge-missing').text(data.length);
    applyMissingFilter();
}
function applyMissingFilter() {
    var vis = applyRowFilter('tbody-missing', $('#search-missing').val(), $('#filter-missing-platform').val(), '');
    $('#count-missing').text(vis+' creator');
}
$('#search-missing, #filter-missing-platform').on('input change', applyMissingFilter);

// ---- PERFORMERS ----
function loadPerformers() {
    var sort = $('#perf-sort').val(), order = $('#perf-order').val();
    _setLoading(1);
    $('#tbody-performers').html('<tr><td colspan="9" class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary" role="status" aria-label="Memuat..."></span></td></tr>');
    $.getJSON(AN_BASE+'ajax/performers-ranking?id_campaign='+AN_CAMPAIGN+'&start_date='+anStart()+'&until_date='+anUntil()+'&sort='+sort+'&order='+order, function(data) {
        _performersData = data;
        renderPerformers();
    }).fail(function() {
        $('#tbody-performers').html('<tr><td colspan="9" class="text-center text-danger py-3"><i class="bi bi-exclamation-circle me-1"></i>Gagal memuat. <button class="btn btn-sm btn-link p-0 text-danger" onclick="loadPerformers()">Coba lagi</button></td></tr>');
    }).always(function() { _setLoading(-1); });
}
function renderPerformers() {
    var data = _performersData;
    if (!data.length) {
        $('#tbody-performers').html('<tr><td colspan="9" class="text-center py-3 text-muted"><i class="bi bi-calendar-x me-1"></i>Tidak ada data posting dalam periode ini. Coba perluas rentang tanggal.</td></tr>');
        return;
    }
    var limit = $('#perf-limit').val();
    var show;
    if (limit === 'bottom10') {
        show = data.slice(-10).reverse();
    } else if (limit === '10') {
        show = data.slice(0, 10);
    } else {
        show = data;
    }
    var html = '';
    $.each(show, function(i, r) {
        var origRank = (limit==='bottom10') ? (data.length - i) : (i+1);
        var rankHtml = '';
        if (limit !== 'bottom10' && origRank<=3) {
            var rankClasses = ['rank-gold','rank-silver','rank-bronze'];
            rankHtml = '<span class="'+rankClasses[origRank-1]+'">★</span> '+origRank;
        } else { rankHtml = origRank; }
        var searchable = (r.nama_creator||'')+' '+(r.platform||'')+' '+(r.link_upload||'');
        var engTip = 'Likes: '+(r.likes_gain>0?'+':'')+numFmt(r.likes_gain)
                   +' | Comments: '+(r.comment_gain>0?'+':'')+numFmt(r.comment_gain)
                   +' | Share/Save: '+(r.share_save_gain>0?'+':'')+numFmt(r.share_save_gain);
        html += '<tr data-searchable="'+htmlEsc(searchable)+'" data-platform="'+htmlEsc(r.platform||'')+'">';
        html += '<td>'+rankHtml+'</td>';
        html += '<td><div class="fw-500 text-truncate" style="max-width:180px">'+htmlEsc(r.nama_creator)+'</div>'
              + (r.posting_at ? '<div class="fs-10 text-muted">'+htmlEsc(r.posting_at)+'</div>' : '')+'</td>';
        html += '<td>'+platformIcon(r.platform)+'</td>';
        html += '<td>'+linkIcon(r.link_upload)+'</td>';
        html += '<td class="text-end'+(r.views_gain<0?' text-danger':'')+'">'+numFmt(r.views_gain)+'</td>';
        html += '<td class="text-end col-hint" title="'+htmlEsc(engTip)+'">'+numFmt(r.engagement_gain)+'</td>';
        html += '<td class="text-end">'+(r.cpm!==null&&r.cpm!==''?'Rp '+numFmt(r.cpm):'-')+'</td>';
        html += '<td class="text-end">Rp '+numFmt(r.total_cost)+'</td>';
        html += '<td class="text-center"><button class="btn btn-xs btn-outline-secondary" '
              + 'aria-label="Lihat trend" data-creator="'+htmlAttrEsc(r.nama_creator||'')+'" onclick="goToTrends(this.dataset.creator)"><i class="bi bi-graph-up"></i></button></td>';
        html += '</tr>';
    });
    $('#tbody-performers').html(html);
    $('#count-perf').text(show.length+(limit==='all'?' total':' / '+data.length));
    updateSortHeaders('tbl-performers', $('#perf-sort').val(), $('#perf-order').val());
    applyPerfFilter();
}
function applyPerfFilter() {
    var vis = applyRowFilter('tbody-performers', $('#search-perf').val(), $('#filter-perf-platform').val(), '');
    $('#count-perf').text(vis+' creator');
}
$('#search-perf, #filter-perf-platform').on('input change', function(){ renderPerformers(); });

function goToTrends(name) {
    $('a[href="#tab-trends"]').tab('show');
    $('#search-trends').val(name);
    setTimeout(function(){ renderTrends(); }, 100);
}

// ---- TRENDS ----
function loadTrends() {
    _setLoading(1);
    $('#tbody-trends').html('<tr><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm text-secondary" role="status" aria-label="Memuat..."></span></td></tr>');
    $.getJSON(AN_BASE+'ajax/creator-trends?id_campaign='+AN_CAMPAIGN+'&start_date='+anStart()+'&until_date='+anUntil(), function(data) {
        _trendsData = data;
        renderTrends();
    }).fail(function() {
        $('#tbody-trends').html('<tr><td colspan="7" class="text-center text-danger py-3"><i class="bi bi-exclamation-circle me-1"></i>Gagal memuat. <button class="btn btn-sm btn-link p-0 text-danger" onclick="loadTrends()">Coba lagi</button></td></tr>');
    }).always(function() { _setLoading(-1); });
}
function renderTrends() {
    _trendCharts.forEach(function(c) { try { c.destroy(); } catch(e) {} });
    _trendCharts = [];

    var data = _trendsData.slice();
    var sortVal = $('#trends-sort').val()||'views_desc';
    if (sortVal==='views_desc') data.sort(function(a,b){ return b.total_views_gain - a.total_views_gain; });
    else if (sortVal==='views_asc') data.sort(function(a,b){ return a.total_views_gain - b.total_views_gain; });
    else if (sortVal==='name_asc') data.sort(function(a,b){ return (a.nama_creator||'').localeCompare(b.nama_creator||''); });
    else if (sortVal==='name_desc') data.sort(function(a,b){ return (b.nama_creator||'').localeCompare(a.nama_creator||''); });

    if (!data.length) {
        $('#tbody-trends').html('<tr><td colspan="7" class="text-center py-3 text-muted"><i class="bi bi-calendar-x me-1"></i>Tidak ada data log dalam periode ini. Coba perluas rentang tanggal.</td></tr>');
        $('#count-trends').text('');
        return;
    }
    var html = '';
    $.each(data, function(i, r) {
        var total = r.total_views_gain || 0;
        var canvasId = 'spark-'+r.id_endorse;
        var searchable = (r.nama_creator||'')+' '+(r.platform||'')+' '+(r.link_upload||'');
        html += '<tr data-searchable="'+htmlEsc(searchable)+'" data-platform="'+htmlEsc(r.platform||'')+'">';
        html += '<td class="text-muted fs-11">'+(i+1)+'</td>';
        html += '<td style="min-width:140px;max-width:180px">'
              + '<div class="fw-500 text-truncate">'+htmlEsc(r.nama_creator)+'</div>'
              + '<div class="fs-10">'+linkIcon(r.link_upload)+'</div>'
              + '</td>';
        html += '<td>'+platformIcon(r.platform)+'</td>';
        html += '<td>'+statusBadge(r.status_endorse)+'</td>';
        html += '<td><div class="d-flex align-items-center gap-2">'
              + '<canvas id="'+canvasId+'" width="220" height="48"></canvas>'
              + '<button class="btn btn-xs btn-outline-primary flex-shrink-0" '
              + 'aria-label="Lihat detail scraping" onclick="_showTrendDetail('+htmlEsc(JSON.stringify(r.id_endorse))+')" title="Lihat detail scraping"><i class="bi bi-table"></i></button>'
              + '</div></td>';
        html += '<td class="text-end'+(total<0?' text-danger':'')+'"><b>'+numFmt(total)+'</b></td>';
        html += '<td class="fs-12">'+(r.posting_at||'-')+'</td>';
        html += '</tr>';
    });
    $('#tbody-trends').html(html);
    $('#count-trends').text(data.length+' konten');
    var _tSortVal = $('#trends-sort').val() || 'views_desc';
    updateSortHeaders('tbl-trends', _tSortVal.indexOf('views') !== -1 ? 'views' : 'name', _tSortVal.endsWith('asc') ? 'asc' : 'desc');

    // Render sparklines
    $.each(data, function(i, r) {
        var ctx = document.getElementById('spark-'+r.id_endorse);
        if (!ctx) return;
        ctx.setAttribute('role', 'img');
        ctx.setAttribute('aria-label', 'Trend views harian: ' + htmlEsc(r.nama_creator));
        _trendCharts.push(new Chart(ctx, {
            type: 'bar',
            data: {
                labels: r.dates,
                datasets: [{ data: r.values, borderWidth: 0,
                    backgroundColor: r.values.map(function(v){
                        return v<0?'rgba(220,53,69,0.75)':'rgba(13,110,253,0.6)';
                    })
                }]
            },
            options: {
                responsive: false, animation: false,
                plugins: { legend:{display:false}, tooltip:{
                    callbacks:{ label: function(c){ return (c.raw>0?'+':'')+numFmt(c.raw)+' views'; } }
                }},
                scales: { x:{display:false}, y:{display:false} }
            }
        }));
    });

    applyTrendsFilter();
}
window._showTrendDetail = function(endorse_id) {
    var r = null;
    for (var i = 0; i < _trendsData.length; i++) {
        if (_trendsData[i].id_endorse == endorse_id) { r = _trendsData[i]; break; }
    }
    if (!r) return;
    showDetailModal(r.nama_creator, (r.platform||'')+(r.link_upload?' | '+r.link_upload:''), r.daily_detail);
};
function applyTrendsFilter() {
    var vis = applyRowFilter('tbody-trends', $('#search-trends').val(), $('#filter-trends-platform').val(), '');
    $('#count-trends').text(vis+' konten');
}
$('#search-trends, #filter-trends-platform').on('input change', applyTrendsFilter);
$('#trends-sort').on('change', renderTrends);

// ---- SORT HELPERS ----
function updateSortHeaders(tableId, activeCol, order) {
    $('#' + tableId + ' thead th.sortable').removeClass('sort-asc sort-desc');
    $('#' + tableId + ' thead th[data-sort="' + activeCol + '"]')
        .addClass(order === 'desc' ? 'sort-desc' : 'sort-asc');
}

// ---- LOAD ALL ----
function loadAll() {
    var s = anStart(), u = anUntil();
    if (s && u && s > u) {
        toastr.warning('Tanggal Mulai tidak boleh setelah Tanggal Akhir.');
        return;
    }
    loadSummary();
    loadMissing();
    loadPerformers();
    loadTrends();
}

$(document).ready(function() {
    loadAll();

    // Column-header sort: Performers (server sort)
    $('#tbl-performers').on('click', 'thead th[data-sort]', function() {
        var col = $(this).data('sort');
        if ($('#perf-sort').val() === col) {
            $('#perf-order').val($('#perf-order').val() === 'desc' ? 'asc' : 'desc');
        } else {
            $('#perf-sort').val(col);
            $('#perf-order').val('desc');
        }
        updateSortHeaders('tbl-performers', col, $('#perf-order').val());
        loadPerformers();
    });

    // Column-header sort: Trends (client sort)
    $('#tbl-trends').on('click', 'thead th[data-sort]', function() {
        var col = $(this).data('sort');
        var cur = $('#trends-sort').val() || 'views_desc';
        var next = col === 'views'
            ? (cur === 'views_desc' ? 'views_asc' : 'views_desc')
            : (cur === 'name_asc'  ? 'name_desc'  : 'name_asc');
        $('#trends-sort').val(next);
        updateSortHeaders('tbl-trends', col, next.endsWith('desc') ? 'desc' : 'asc');
        renderTrends();
    });

    // Column-header sort: Missing (client sort)
    $('#tbl-missing').on('click', 'thead th[data-sort]', function() {
        _missingSortOrder = _missingSortOrder === 'desc' ? 'asc' : 'desc';
        updateSortHeaders('tbl-missing', 'days', _missingSortOrder);
        renderMissing();
    });

});
</script>
