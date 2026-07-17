<?php
$filter_id_campaign = isset($filter_id_campaign) ? intval($filter_id_campaign) : 0;
$campaigns = isset($campaigns) ? $campaigns : [];
?>

<style>
    .queue-summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
    .queue-card { flex: 1 1 160px; padding: 12px 16px; border-radius: 8px; background: #fff; border: 1px solid #e5e7eb; }
    .queue-card .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }
    .queue-card .value { font-size: 24px; font-weight: 600; margin-top: 4px; }
    .queue-card.pending .value { color: #6b7280; }
    .queue-card.processing .value { color: #2563eb; }
    .queue-card.completed .value { color: #059669; }
    .queue-card.failed .value { color: #dc2626; }
    .queue-status-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 500; }
    .queue-status-pill.pending { background: #f3f4f6; color: #374151; }
    .queue-status-pill.processing { background: #dbeafe; color: #1e40af; }
    .queue-status-pill.retrying { background: #fef3c7; color: #92400e; }
    .queue-status-pill.completed { background: #d1fae5; color: #065f46; }
    .queue-status-pill.failed { background: #fee2e2; color: #991b1b; }
    .queue-error-msg { font-size: 12px; color: #991b1b; word-break: break-word; max-width: 320px; }
    .queue-link { font-size: 12px; color: #2563eb; text-decoration: none; word-break: break-all; }
    .queue-link:hover { text-decoration: underline; }
    .queue-filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 12px; }
    .queue-filters .form-group { margin-bottom: 0; }
    #queueTable td { vertical-align: top; }
    .queue-health { display: none; margin-bottom: 16px; }
    .queue-health.stalled { display: block; border: 1px solid #f59e0b; background: #fffbeb; color: #92400e; }
    .queue-meta { font-size: 12px; color: #6b7280; }
    .queue-action-link { font-size: 12px; }
    .queue-history-list { max-height: 360px; overflow-y: auto; }
    .queue-history-item { border-bottom: 1px solid #e5e7eb; padding: 10px 0; }
    .queue-history-item:last-child { border-bottom: none; }
    .queue-operator-card { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 16px; margin-bottom: 16px; }
    .queue-operator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
    .queue-operator-item { border: 1px solid #eef2f7; border-radius: 8px; padding: 12px; background: #f8fafc; }
    .queue-operator-item .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }
    .queue-operator-item .value { font-size: 15px; font-weight: 600; color: #111827; margin-top: 4px; }
    .queue-pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 12px; }
    .queue-pagination .summary { font-size: 12px; color: #6b7280; }
</style>

<div class="container-fluid pt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Antrian Refresh Konten</h4>
            <small class="text-muted">Status proses sinkronisasi data sosial media untuk endorse content.</small>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <button class="btn btn-outline-success btn-sm" id="btnRunWorker">
                <i class="fa fa-play"></i> Proses Sekarang
            </button>
            <button class="btn btn-outline-warning btn-sm" id="btnResetStuck">
                <i class="fa fa-undo"></i> Lepas yang Macet
            </button>
            <button class="btn btn-outline-primary btn-sm" id="btnEnqueueDailyNow">
                <i class="fa fa-calendar-plus"></i> Enqueue Harian Sekarang
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="btnClearQueue">
                <i class="fa fa-trash"></i> Clear Semua Data
            </button>
            <button class="btn btn-outline-danger btn-sm" id="btnRetryFailed" disabled>
                <i class="fa fa-redo"></i> Retry Gagal Terpilih
            </button>
        </div>
    </div>

    <div class="queue-summary">
        <div class="queue-card pending"><div class="label">Menunggu</div><div class="value" id="sum-pending">0</div></div>
        <div class="queue-card processing"><div class="label">Berjalan</div><div class="value" id="sum-processing">0</div></div>
        <div class="queue-card completed"><div class="label">Berhasil</div><div class="value" id="sum-completed">0</div></div>
        <div class="queue-card failed"><div class="label">Gagal</div><div class="value" id="sum-failed">0</div></div>
    </div>

    <div class="alert queue-health" id="queueHealthBanner"></div>

    <div class="queue-operator-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h6 class="mb-1">Konteks Operator</h6>
                <small class="text-muted">Pantau status worker, aktivitas terakhir, dan jalankan enqueue harian manual bila dibutuhkan.</small>
            </div>
            <span class="queue-status-pill pending" id="queueWorkerStatusLabel">Idle</span>
        </div>
        <div class="queue-operator-grid">
            <div class="queue-operator-item">
                <div class="label">Jadwal Otomatis</div>
                <div class="value" id="queueAutoScheduleText">Setiap hari 11:30 WIB</div>
            </div>
            <div class="queue-operator-item">
                <div class="label">Aktivitas Terakhir</div>
                <div class="value" id="queueLastActivityText">-</div>
            </div>
            <div class="queue-operator-item">
                <div class="label">Pending Tertua</div>
                <div class="value" id="queueOldestPendingText">-</div>
            </div>
            <div class="queue-operator-item">
                <div class="label">Selesai Terakhir</div>
                <div class="value" id="queueLastCompletedText">-</div>
            </div>
        </div>
    </div>

    <div class="queue-filters card p-3">
        <div class="form-group">
            <label class="small">Campaign</label>
            <select id="filter-campaign" class="form-control form-control-sm">
                <option value="0">Semua Campaign</option>
                <?php foreach ($campaigns as $c): ?>
                    <option value="<?= intval($c['id']) ?>" <?= $filter_id_campaign == intval($c['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="small d-block">Status</label>
            <label class="me-2"><input type="checkbox" class="filter-status" value="pending" checked> Menunggu</label>
            <label class="me-2"><input type="checkbox" class="filter-status" value="processing" checked> Berjalan</label>
            <label class="me-2"><input type="checkbox" class="filter-status" value="completed" checked> Berhasil</label>
            <label class="me-2"><input type="checkbox" class="filter-status" value="failed" checked> Gagal</label>
        </div>
        <div class="form-group">
            <label class="small">Rentang</label>
            <select id="filter-since" class="form-control form-control-sm">
                <option value="6">6 jam terakhir</option>
                <option value="72">3 hari terakhir</option>
                <option value="168" selected>7 hari terakhir</option>
                <option value="0">Semua data</option>
            </select>
        </div>
        <div class="form-group">
            <label class="small">Cari</label>
            <input type="text" id="filter-keyword" class="form-control form-control-sm" placeholder="Campaign, influencer, link, error...">
        </div>
        <div class="form-group">
            <label class="small">Per Halaman</label>
            <select id="filter-length" class="form-control form-control-sm">
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
        <div class="form-group">
            <button id="btnReload" class="btn btn-primary btn-sm"><i class="fa fa-sync"></i> Refresh</button>
        </div>
    </div>

    <div class="card p-3">
        <table id="queueTable" class="table table-hover" style="width:100%">
            <thead>
                <tr>
                    <th style="width:30px"><input type="checkbox" id="checkAll"></th>
                    <th>Campaign</th>
                    <th>Influencer</th>
                    <th>Platform</th>
                    <th>Konten</th>
                    <th>Status</th>
                    <th>Percobaan</th>
                    <th>Diantrikan</th>
                    <th>Mulai</th>
                    <th>Selesai</th>
                    <th>Pesan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <div class="queue-pagination">
            <div class="summary" id="queuePageSummary">Menampilkan 0 data</div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnPrevPage">Sebelumnya</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnNextPage">Berikutnya</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="queueHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Riwayat Percobaan Queue</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="queue-meta mb-2" id="queueHistoryMeta"></div>
                <div class="queue-history-list" id="queueHistoryList">
                    <div class="text-muted">Memuat riwayat...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const baseUrl = '<?= base_url() ?>';
    let pollTimer = null;
    let historyModal = null;
    let currentStart = 0;
    let currentLength = 25;
    let currentTotal = 0;
    let keywordTimer = null;

    function getStatusFilter() {
        return $('.filter-status:checked').map(function() {
            return this.value;
        }).get();
    }

    function statusPill(status) {
        const labels = {
            pending: 'Menunggu',
            processing: 'Berjalan',
            retrying: 'Retry',
            completed: 'Berhasil',
            failed: 'Gagal'
        };
        return '<span class="queue-status-pill ' + status + '">' + (labels[status] || status) + '</span>';
    }

    function escHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function formatDateTime(value) {
        if (!value) {
            return '-';
        }
        if (window.moment) {
            const date = moment(value);
            if (date.isValid()) {
                return date.format('DD/MM/YYYY HH:mm');
            }
        }
        return value;
    }

    function setWorkerStatus(text, variant) {
        $('#queueWorkerStatusLabel')
            .removeClass('pending processing retrying completed failed')
            .addClass(variant)
            .text(text);
    }

    function renderLoadError(message) {
        $('#sum-pending, #sum-processing, #sum-completed, #sum-failed').text('0');
        $('#checkAll').prop('checked', false);
        $('#queueHealthBanner').removeClass('stalled').hide();
        $('#queuePageSummary').text('Menampilkan 0 data');
        $('#btnPrevPage, #btnNextPage').prop('disabled', true);
        $('#queueTable tbody').html(
            '<tr><td colspan="12" class="text-center text-danger py-4">' + escHtml(message) + '</td></tr>'
        );
        updateRetryButton();
        schedulePoll(false);
    }

    function formatMetaLabel(label, value) {
        if (!value) {
            return '';
        }
        return '<span class="me-3"><strong>' + escHtml(label) + ':</strong> ' + escHtml(value) + '</span>';
    }

    function renderHealth(health) {
        const $banner = $('#queueHealthBanner');
        if (!health || !health.is_stalled) {
            $banner.removeClass('stalled').hide().empty();
            return;
        }

        let html = '<strong>Antrian terlihat macet.</strong> ';
        html += 'Masih ada ' + escHtml(health.pending_total || 0) + ' item menunggu tanpa proses berjalan.';
        if (health.oldest_pending_at) {
            html += ' Pending tertua: ' + escHtml(formatDateTime(health.oldest_pending_at)) + '.';
        }
        if (health.last_started_at) {
            html += ' Aktivitas worker terakhir: ' + escHtml(formatDateTime(health.last_started_at)) + '.';
        }
        if (health.stall_label) {
            html += '<br><strong>Kemungkinan penyebab:</strong> ' + escHtml(health.stall_label) + '.';
        }

        $banner.addClass('stalled').html(html).show();
    }

    function updateOperatorContext(resp) {
        const health = resp.health || {};
        const stalled = !!health.is_stalled;
        const processing = parseInt(health.processing_total || 0, 10);
        const pending = parseInt(health.pending_total || 0, 10);
        const workerStatus = resp.worker_status || (stalled ? 'Stalled' : 'Idle');
        let variant = 'completed';

        if (stalled) {
            variant = 'failed';
        } else if (processing > 0) {
            variant = 'processing';
        } else if (pending > 0) {
            variant = 'retrying';
        }

        setWorkerStatus(workerStatus, variant);
        $('#queueAutoScheduleText').text(resp.auto_enqueue_schedule || 'Setiap hari 11:30 WIB');
        $('#queueLastActivityText').text(formatDateTime(resp.last_activity_at || health.last_started_at || health.oldest_pending_at));
        $('#queueOldestPendingText').text(formatDateTime(health.oldest_pending_at));
        $('#queueLastCompletedText').text(formatDateTime(health.last_completed_at));
    }

    function updatePaginationSummary(total, rowCount) {
        if (!total || rowCount === 0) {
            $('#queuePageSummary').text('Menampilkan 0 data');
            $('#btnPrevPage, #btnNextPage').prop('disabled', true);
            return;
        }

        const from = currentStart + 1;
        const to = currentStart + rowCount;
        $('#queuePageSummary').text('Menampilkan ' + from + '-' + to + ' dari ' + total + ' data');
        $('#btnPrevPage').prop('disabled', currentStart <= 0);
        $('#btnNextPage').prop('disabled', (currentStart + currentLength) >= total);
    }

    function openHistory(queueId, meta) {
        if (!historyModal && window.bootstrap && bootstrap.Modal) {
            historyModal = new bootstrap.Modal(document.getElementById('queueHistoryModal'));
        }

        $('#queueHistoryMeta').html(
            formatMetaLabel('Campaign', meta.campaign_title) +
            formatMetaLabel('Influencer', meta.influencer_name) +
            formatMetaLabel('Status', meta.status)
        );
        $('#queueHistoryList').html('<div class="text-muted">Memuat riwayat...</div>');

        $.ajax({
            url: baseUrl + 'endorse/queue-history',
            method: 'GET',
            data: { id: queueId },
            dataType: 'json',
            success: function(resp) {
                const rows = resp.data || [];
                if (!rows.length) {
                    $('#queueHistoryList').html('<div class="text-muted">Belum ada riwayat percobaan tersimpan.</div>');
                } else {
                    const html = rows.map(function(row) {
                        return '' +
                            '<div class="queue-history-item">' +
                                '<div class="d-flex justify-content-between align-items-start gap-2">' +
                                    '<div><strong>Percobaan #' + escHtml(row.attempt_no) + '</strong> ' + statusPill(row.status) + '</div>' +
                                    '<div class="queue-meta">' + escHtml(row.worker_id || '-') + '</div>' +
                                '</div>' +
                                '<div class="queue-meta mt-2">' +
                                    formatMetaLabel('Mulai', formatDateTime(row.started_at)) +
                                    formatMetaLabel('Selesai', formatDateTime(row.finished_at)) +
                                    formatMetaLabel('Error', row.error_class) +
                                '</div>' +
                                (row.error_message ? '<div class="queue-error-msg mt-2">' + escHtml(row.error_message) + '</div>' : '') +
                            '</div>';
                    }).join('');
                    $('#queueHistoryList').html(html);
                }
                if (historyModal) {
                    historyModal.show();
                }
            },
            error: function() {
                $('#queueHistoryList').html('<div class="text-danger">Gagal memuat riwayat percobaan.</div>');
                if (historyModal) {
                    historyModal.show();
                }
            }
        });
    }

    function loadData(resetPage) {
        if (resetPage) {
            currentStart = 0;
        }

        currentLength = parseInt($('#filter-length').val() || '25', 10);
        const params = {
            id_campaign: $('#filter-campaign').val(),
            status: getStatusFilter().join(','),
            since_hours: $('#filter-since').val(),
            keyword: $('#filter-keyword').val(),
            length: currentLength,
            start: currentStart
        };

        $.ajax({
            url: baseUrl + 'endorse/queue-data',
            method: 'GET',
            data: params,
            dataType: 'json',
            success: function(resp) {
                $('#sum-pending').text(resp.summary.pending || 0);
                $('#sum-processing').text(resp.summary.processing || 0);
                $('#sum-completed').text(resp.summary.completed || 0);
                $('#sum-failed').text(resp.summary.failed || 0);
                $('#checkAll').prop('checked', false);
                renderHealth(resp.health || {});
                updateOperatorContext(resp);

                const rows = resp.data || [];
                currentTotal = parseInt(resp.recordsFiltered || 0, 10);
                const $tbody = $('#queueTable tbody').empty();

                if (!rows.length) {
                    $tbody.append('<tr><td colspan="12" class="text-center text-muted py-4">Tidak ada data dalam rentang waktu yang dipilih.</td></tr>');
                } else {
                    rows.forEach(function(row) {
                        const checkable = row.status === 'failed';
                        const checkbox = checkable
                            ? '<input type="checkbox" class="row-check" value="' + row.id + '">'
                            : '';
                        let action = '<a href="#!" class="queue-action-link btn-history me-2" data-id="' + row.id + '" data-campaign="' + escHtml(row.campaign_title || ('#' + row.id_campaign)) + '" data-influencer="' + escHtml(row.influencer_name || '-') + '" data-status="' + escHtml(row.status) + '">Riwayat</a>';
                        if (row.redirect_url) {
                            action += '<a class="queue-action-link" href="' + escHtml(row.redirect_url) + '">Lihat Konten</a>';
                        }

                        $tbody.append(
                            '<tr>' +
                                '<td>' + checkbox + '</td>' +
                                '<td>' + escHtml(row.campaign_title || '#' + row.id_campaign) + '</td>' +
                                '<td>' + escHtml(row.influencer_name || '-') + '</td>' +
                                '<td>' + escHtml(row.platform) + '</td>' +
                                '<td><a class="queue-link" target="_blank" href="' + escHtml(row.link_upload) + '">' + escHtml(row.link_upload) + '</a></td>' +
                                '<td>' + statusPill(row.status) + '</td>' +
                                '<td>' + escHtml(row.attempts) + ' / ' + escHtml(row.max_attempts) + '</td>' +
                                '<td><small>' + escHtml(formatDateTime(row.queued_at)) + '</small></td>' +
                                '<td><small>' + escHtml(formatDateTime(row.started_at)) + '</small></td>' +
                                '<td><small>' + escHtml(formatDateTime(row.completed_at)) + '</small></td>' +
                                '<td><div class="queue-error-msg">' + escHtml(row.error_message || '') + '</div></td>' +
                                '<td>' + action + '</td>' +
                            '</tr>'
                        );
                    });
                }

                updateRetryButton();
                updatePaginationSummary(currentTotal, rows.length);

                const stillRunning = resp.health && parseInt(resp.health.active_total || 0, 10) > 0;
                schedulePoll(stillRunning);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.msg
                    ? xhr.responseJSON.msg
                    : 'Gagal memuat data antrian. Silakan refresh halaman.';
                renderLoadError(msg);
            }
        });
    }

    function schedulePoll(active) {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (active) {
            pollTimer = setTimeout(function() {
                loadData(false);
            }, 5000);
        }
    }

    function updateRetryButton() {
        const count = $('.row-check:checked').length;
        $('#btnRetryFailed')
            .prop('disabled', count === 0)
            .text(count > 0 ? 'Retry Gagal Terpilih (' + count + ')' : 'Retry Gagal Terpilih');
    }

    $('#filter-campaign, #filter-since, #filter-length').on('change', function() {
        loadData(true);
    });

    $(document).on('change', '.filter-status', function() {
        loadData(true);
    });

    $('#filter-keyword').on('input', function() {
        clearTimeout(keywordTimer);
        keywordTimer = setTimeout(function() {
            loadData(true);
        }, 350);
    });

    $('#btnReload').on('click', function() {
        loadData(false);
    });

    $('#checkAll').on('change', function() {
        $('.row-check').prop('checked', this.checked);
        updateRetryButton();
    });

    $(document).on('change', '.row-check', updateRetryButton);

    $(document).on('click', '.btn-history', function(e) {
        e.preventDefault();
        openHistory($(this).data('id'), {
            campaign_title: $(this).data('campaign'),
            influencer_name: $(this).data('influencer'),
            status: $(this).data('status')
        });
    });

    $('#btnPrevPage').on('click', function() {
        if (currentStart <= 0) {
            return;
        }
        currentStart = Math.max(0, currentStart - currentLength);
        loadData(false);
    });

    $('#btnNextPage').on('click', function() {
        if ((currentStart + currentLength) >= currentTotal) {
            return;
        }
        currentStart += currentLength;
        loadData(false);
    });

    $('#btnRetryFailed').on('click', function() {
        const ids = $('.row-check:checked').map(function() {
            return this.value;
        }).get();
        if (!ids.length) {
            return;
        }

        const $btn = $(this).prop('disabled', true).text('Memproses...');
        $.ajax({
            url: baseUrl + 'endorse/force-retry',
            method: 'POST',
            data: { ids: ids.join(',') },
            dataType: 'json',
            success: function(resp) {
                alert(resp.msg);
                loadData(false);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.msg
                    ? xhr.responseJSON.msg
                    : 'Gagal memproses retry antrian.';
                alert(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).text('Retry Gagal Terpilih');
            }
        });
    });

    $('#btnClearQueue').on('click', function() {
        if (!confirm('Hapus semua data queue dan riwayat percobaan? Data baru akan dibuat lagi saat ada proses refresh yang masuk.')) {
            return;
        }

        const $btn = $(this).prop('disabled', true).text('Menghapus...');
        $.ajax({
            url: baseUrl + 'endorse/clear-queue',
            method: 'POST',
            dataType: 'json',
            success: function(resp) {
                alert(resp.msg || 'Data antrian berhasil dihapus.');
                loadData(true);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.msg
                    ? xhr.responseJSON.msg
                    : 'Gagal menghapus data antrian.';
                alert(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-trash"></i> Clear Semua Data');
            }
        });
    });

    $('#btnEnqueueDailyNow').on('click', function() {
        const $btn = $(this).prop('disabled', true).html('<i class="fa fa-circle-o-notch fa-spin"></i> Memproses...');
        $.ajax({
            url: baseUrl + 'endorse/queue-enqueue-daily',
            method: 'POST',
            dataType: 'json',
            success: function(resp) {
                const summary = resp.data || {};
                alert(
                    (resp.msg || 'Enqueue harian selesai.') + '\n' +
                    'Campaign diproses: ' + (summary.processed_campaigns || 0) + '/' + (summary.campaign_total || 0) + '\n' +
                    'Queue baru: ' + (summary.enqueued || 0) + '\n' +
                    'Duplikat aktif: ' + (summary.skipped_duplicates || 0)
                );
                loadData(true);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.msg
                    ? xhr.responseJSON.msg
                    : 'Gagal menjalankan enqueue harian.';
                alert(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-calendar-plus"></i> Enqueue Harian Sekarang');
            }
        });
    });

    $('#btnRunWorker').on('click', function() {
        const $btn = $(this).prop('disabled', true).html('<i class="fa fa-circle-o-notch fa-spin"></i> Memproses...');
        $.ajax({
            url: baseUrl + 'endorse/run-worker',
            method: 'POST',
            dataType: 'json',
            timeout: 70000,
            success: function(resp) {
                alert(resp.msg || ('Worker selesai: ' + (resp.processed || 0) + ' item diproses.'));
                loadData(true);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.msg
                    ? xhr.responseJSON.msg
                    : 'Gagal menjalankan worker atau proses melewati batas waktu.';
                alert(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-play"></i> Proses Sekarang');
            }
        });
    });

    $('#btnResetStuck').on('click', function() {
        if (!confirm('Kembalikan item processing yang macet lebih dari 5 menit ke antrian?')) {
            return;
        }
        const $btn = $(this).prop('disabled', true).html('<i class="fa fa-circle-o-notch fa-spin"></i> Melepas...');
        $.ajax({
            url: baseUrl + 'endorse/reset-stuck',
            method: 'POST',
            dataType: 'json',
            success: function(resp) {
                alert(resp.msg || 'Item yang macet sudah dikembalikan ke antrian.');
                loadData(true);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.msg
                    ? xhr.responseJSON.msg
                    : 'Gagal melepas item yang macet.';
                alert(msg);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-undo"></i> Lepas yang Macet');
            }
        });
    });

    loadData(true);
})();
</script>
