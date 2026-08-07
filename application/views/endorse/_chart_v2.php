<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Endorse analytics V2 — dashboard cards + dual-series chart.
 *
 * Included from endorse/all.php only when ENDORSE_ANALYTICS_V2 is 'on'. The
 * legacy cards (#summary-mar-*) and chart (#summary-chart) remain in place and
 * untouched, so switching the flag back to 'off' restores the previous UI with
 * no other change.
 *
 * Bars  = observed_daily_growth  (left axis)
 * Line  = total_observed_views   (right axis)
 */
?>
<style>
    .v2-card { border: 1px solid #dee2e6; border-radius: .375rem; padding: .75rem .9rem; height: 100%; background: #fff; }
    .v2-card .v2-label { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #6c757d; }
    .v2-card .v2-value { font-size: 24px; font-weight: 600; line-height: 1.2; }
    .v2-card .v2-sub { font-size: 11px; color: #6c757d; }
    .v2-card .v2-meta { font-size: 11px; color: #6c757d; margin-top: .35rem; }
    .v2-card .v2-delta { font-size: 12px; font-weight: 600; margin-top: .15rem; }
    .v2-card .v2-up { color: #0f5132; }
    .v2-card .v2-down { color: #842029; }
    .v2-badge { font-size: 10px; padding: .1rem .4rem; border-radius: .25rem; font-weight: 600; }
    .v2-badge-complete { background: #d1e7dd; color: #0f5132; }
    .v2-badge-partial { background: #fff3cd; color: #664d03; }
    .v2-badge-insufficient { background: #f8d7da; color: #842029; }
    .v2-toggle .btn { font-size: 12px; }
    .v2-note { font-size: 11px; color: #6c757d; }
    #v2-chart-wrap { position: relative; height: 320px; }
    @media (max-width: 767.98px) {
        #v2-chart-wrap { height: 260px; }
        .v2-card .v2-value { font-size: 20px; }
    }
</style>

<div class="col-lg-12 mb-3">
    <div class="card summary">
        <div class="d-flex align-items-center justify-content-between flex-wrap mb-2">
            <h3 class="text-primary fw-600 mb-1">Grafik Campaign (V2)</h3>
            <div class="btn-group btn-group-sm v2-toggle" role="group" aria-label="Pilih metrik">
                <button type="button" class="btn btn-outline-primary" data-v2-mode="growth">Daily Growth</button>
                <button type="button" class="btn btn-outline-primary" data-v2-mode="total">Total Views</button>
                <button type="button" class="btn btn-primary active" data-v2-mode="both">Both</button>
            </div>
        </div>

        <div class="row g-2 mb-3" id="v2-cards">
            <!-- Card A — Total Views -->
            <div class="col-6 col-lg-3">
                <div class="v2-card">
                    <div class="v2-label">Total Views</div>
                    <div class="v2-value" id="v2-total-views">&mdash;</div>
                    <div class="v2-sub" id="v2-total-views-sub">Terakhir teramati</div>
                    <div class="v2-delta" id="v2-total-views-delta"></div>
                    <div class="v2-meta" id="v2-total-views-meta"></div>
                </div>
            </div>
            <!-- Card B — Growth in selected period -->
            <div class="col-6 col-lg-3">
                <div class="v2-card">
                    <div class="v2-label">Pertumbuhan Views</div>
                    <div class="v2-value" id="v2-growth">&mdash;</div>
                    <div class="v2-sub" id="v2-growth-sub"></div>
                    <div class="v2-meta" id="v2-growth-meta"></div>
                </div>
            </div>
            <!-- Card C — Opening views -->
            <div class="col-6 col-lg-3">
                <div class="v2-card">
                    <div class="v2-label">Opening Views</div>
                    <div class="v2-value" id="v2-opening">&mdash;</div>
                    <div class="v2-sub">Sudah ada saat observasi pertama</div>
                    <div class="v2-meta" id="v2-opening-meta"></div>
                </div>
            </div>
            <!-- Card D — Data coverage -->
            <div class="col-6 col-lg-3">
                <div class="v2-card">
                    <div class="v2-label">Cakupan Data</div>
                    <div class="v2-value" id="v2-coverage">&mdash;</div>
                    <div class="v2-sub" id="v2-coverage-sub">konten teramati</div>
                    <div class="v2-meta" id="v2-coverage-meta"></div>
                </div>
            </div>
        </div>

        <div id="v2-chart-wrap"><canvas id="v2-chart"></canvas></div>
        <div class="v2-note mt-2" id="v2-footnote"></div>
    </div>
</div>

<script>
    (function () {
        var V2_ENDPOINT = '<?= base_url() ?>ajax/get-chart-campaign-v2';
        var v2Mode = 'both';
        var v2Data = null;

        function fmt(n) {
            if (n === null || n === undefined) return '—';
            return Number(n).toLocaleString('id-ID');
        }

        function badge(status) {
            var cls = 'v2-badge-' + (status || 'insufficient');
            var label = { complete: 'Lengkap', partial: 'Sebagian', insufficient: 'Tidak memadai' }[status] || 'Tidak memadai';
            return '<span class="v2-badge ' + cls + '">' + label + '</span>';
        }

        function renderCards(payload) {
            var s = payload.summary || {};
            var until = (payload.meta && payload.meta.until) || '';
            var from = (payload.meta && payload.meta.from) || '';
            var observed = s.latest_observed_date || until;

            // --- Card A: Total Views, with the day-over-day change ---
            $('#v2-total-views').text(fmt(s.total_views_at_end_date));
            $('#v2-total-views-sub').text('Terakhir teramati pada ' + observed);

            if (s.total_views_change_vs_previous_day === null || s.total_views_change_vs_previous_day === undefined) {
                $('#v2-total-views-delta').html('<span class="text-muted">Belum ada hari pembanding</span>');
            } else {
                var chg = Number(s.total_views_change_vs_previous_day);
                var cls = chg < 0 ? 'v2-down' : 'v2-up';
                $('#v2-total-views-delta').html(
                    '<span class="' + cls + '">' + (chg < 0 ? '' : '+') + fmt(chg) + '</span>' +
                    ' <span class="text-muted">vs ' + s.previous_observed_date + '</span>'
                );
            }

            // The cumulative total moves by growth AND by opening views of any
            // content that joined the population. Spelling that out stops the
            // number being misread as daily growth.
            $('#v2-total-views-meta').html(
                (s.total_views_change_vs_previous_day === null || s.total_views_change_vs_previous_day === undefined
                    ? ''
                    : fmt(s.latest_daily_growth) + ' pertumbuhan + ' + fmt(s.latest_opening_views) + ' opening<br>') +
                fmt(s.included_post_count) + ' konten &middot; ' +
                fmt(s.unresolved_post_count) + ' belum teridentifikasi'
            );

            // --- Card B: growth over the period, with per-day context ---
            $('#v2-growth').text(fmt(s.growth_in_selected_period));
            $('#v2-growth-sub').text(from + ' s/d ' + until);

            var growthMeta = [];
            if (s.observed_day_count > 0) {
                growthMeta.push('&#8960; ' + fmt(s.average_daily_growth) + ' / hari' +
                    ' <span class="text-muted">(' + s.observed_day_count + ' hari teramati)</span>');
            }
            if (s.peak_daily_growth_date) {
                growthMeta.push('Tertinggi ' + s.peak_daily_growth_date + ': ' + fmt(s.peak_daily_growth));
            }
            $('#v2-growth-meta').html(growthMeta.join('<br>') || 'Belum ada hari teramati.');

            // --- Card C: opening views, with the latest day's contribution ---
            $('#v2-opening').text(fmt(s.opening_views_in_selected_period));
            $('#v2-opening-meta').html(
                (s.latest_observed_date
                    ? observed + ': ' + fmt(s.latest_opening_views) + '<br>'
                    : '') +
                'Bukan pertumbuhan yang diperoleh pada hari tersebut.'
            );

            // --- Card D: coverage, broken down per day ---
            var dayCounts = s.completeness_day_counts || {};
            $('#v2-coverage').text(fmt(s.included_post_count));
            $('#v2-coverage-meta').html(
                fmt(s.unresolved_post_count) + ' belum teridentifikasi &middot; ' +
                fmt(s.duplicate_group_count) + ' grup duplikat<br>' +
                badge(s.data_completeness) + ' ' +
                '<span class="text-muted">' +
                    (dayCounts.complete || 0) + ' lengkap &middot; ' +
                    (dayCounts.partial || 0) + ' sebagian &middot; ' +
                    (dayCounts.insufficient || 0) + ' kurang' +
                '</span>'
            );

            var notes = [];
            if (s.unresolved_post_count > 0) {
                notes.push('Konten tanpa snapshot provider dihitung sebagai belum teridentifikasi, bukan sebagai 0 views.');
            }
            if (s.carried_forward_count > 0) {
                notes.push(fmt(s.carried_forward_count) + ' nilai kumulatif dibawa dari observasi sebelumnya.');
            }
            if (s.growth_since_last_observation > 0) {
                notes.push(fmt(s.growth_since_last_observation) + ' views tumbuh melintasi jeda observasi dan tidak dibebankan ke satu tanggal.');
            }
            if (s.negative_anomaly_count > 0) {
                notes.push(s.negative_anomaly_count + ' anomali: views kumulatif turun dibanding observasi sebelumnya.');
            }
            notes.push('Total views = ' + (payload.metric_definition ? payload.metric_definition.total_observed_views : ''));
            $('#v2-footnote').html(notes.join('<br>'));
        }

        function renderChart(payload) {
            var canvas = document.getElementById('v2-chart');
            if (!canvas) return;
            // The legacy chart leaks instances via a randomised canvas id; V2
            // uses a stable id and always destroys the previous instance.
            var existing = Chart.getChart(canvas);
            if (existing) existing.destroy();

            var dates = payload.dates || [];
            var labels = dates.map(function (d) { return d.date; });
            var growth = dates.map(function (d) { return d.observed_daily_growth; });
            var total = dates.map(function (d) { return d.total_observed_views; });

            var datasets = [];
            if (v2Mode === 'growth' || v2Mode === 'both') {
                datasets.push({
                    type: 'bar',
                    label: 'Daily Observed Growth',
                    data: growth,
                    yAxisID: 'y',
                    backgroundColor: 'rgba(13,110,253,0.55)',
                    borderColor: 'rgba(13,110,253,1)',
                    borderWidth: 1,
                    order: 2
                });
            }
            if (v2Mode === 'total' || v2Mode === 'both') {
                datasets.push({
                    type: 'line',
                    label: 'Total Observed Views',
                    data: total,
                    yAxisID: v2Mode === 'both' ? 'y1' : 'y',
                    borderColor: 'rgba(25,135,84,1)',
                    backgroundColor: 'rgba(25,135,84,0.1)',
                    borderWidth: 2,
                    pointRadius: 2,
                    tension: 0.25,
                    fill: false,
                    order: 1
                });
            }

            var scales = {
                x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 10, font: { size: 11 } } },
                y: {
                    position: 'left',
                    beginAtZero: true,
                    title: { display: true, text: v2Mode === 'total' ? 'Total Views' : 'Daily Growth', font: { size: 11 } },
                    ticks: { callback: function (v) { return fmt(v); }, font: { size: 10 } }
                }
            };
            if (v2Mode === 'both') {
                scales.y1 = {
                    position: 'right',
                    beginAtZero: true,
                    title: { display: true, text: 'Total Views', font: { size: 11 } },
                    grid: { drawOnChartArea: false },
                    ticks: { callback: function (v) { return fmt(v); }, font: { size: 10 } }
                };
            }

            new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: scales,
                    plugins: {
                        datalabels: { display: false },
                        legend: { display: true, labels: { boxWidth: 12, font: { size: 11 } } },
                        tooltip: {
                            callbacks: {
                                title: function (items) { return items.length ? items[0].label : ''; },
                                label: function () { return null; },
                                afterBody: function (items) {
                                    if (!items.length) return [];
                                    var d = dates[items[0].dataIndex];
                                    if (!d) return [];
                                    var lines = [
                                        'Daily observed growth: ' + (d.observed_daily_growth === null ? 'tidak ada data' : fmt(d.observed_daily_growth)),
                                        'Total observed views: ' + fmt(d.total_observed_views),
                                        'Opening views: ' + fmt(d.opening_views),
                                        'Konten diikutkan: ' + fmt(d.included_post_count),
                                        'Belum teridentifikasi: ' + fmt(d.unresolved_post_count),
                                        'Kelengkapan: ' + d.data_completeness
                                    ];
                                    if (d.unresolved_post_count > 0) {
                                        lines.push('No successful snapshot for ' + d.unresolved_post_count + ' posts');
                                        lines.push('Growth shown only for observed posts');
                                    }
                                    if (d.carried_forward_count > 0) {
                                        lines.push(d.carried_forward_count + ' nilai dibawa dari observasi sebelumnya');
                                    }
                                    if (d.growth_since_last_observation > 0) {
                                        lines.push('Termasuk ' + fmt(d.growth_since_last_observation) + ' dari jeda observasi');
                                    }
                                    if (d.duplicate_group_count > 0) {
                                        lines.push(d.duplicate_group_count + ' grup konten duplikat');
                                    }
                                    return lines;
                                }
                            }
                        }
                    }
                }
            });
        }

        function render() {
            if (!v2Data) return;
            renderCards(v2Data);
            renderChart(v2Data);
        }

        window.getChartV2 = function () {
            var params = {};
            new URLSearchParams(window.location.search).forEach(function (v, k) { params[k] = v; });
            params['start_date'] = $('#chart_start_date').val() || params['start_date'] || '';
            params['until_date'] = $('#chart_until_date').val() || params['until_date'] || '';

            $.ajax({
                dataType: 'json',
                url: V2_ENDPOINT + '?' + $.param(params),
                success: function (payload) {
                    if (!payload || payload.enabled === false) return;
                    v2Data = payload;
                    render();
                },
                error: function (xhr) {
                    if (xhr.status === 410) return; // flag is off; legacy chart stands alone
                    $('#v2-footnote').html('<span class="text-danger">Gagal memuat analitik V2.</span>');
                }
            });
        };

        $(document).on('click', '.v2-toggle .btn', function () {
            v2Mode = $(this).data('v2-mode');
            $('.v2-toggle .btn').removeClass('btn-primary active').addClass('btn-outline-primary');
            $(this).removeClass('btn-outline-primary').addClass('btn-primary active');
            renderChart(v2Data || { dates: [] });
        });

        $(function () { window.getChartV2(); });
    })();
</script>
