<div class="w-100">
    <div class="row align-items-center mb-3">
        <div class="col-lg-8">
            <h3 class="text-primary fw-600 mb-1">ENDORSE QUEUE</h3>
            <p class="text-muted mb-0">Pantau antrian refresh endorse, retry data gagal, dan cek worker yang stalled.</p>
        </div>
        <div class="col-lg-4 text-end">
            <button type="button" class="btn btn-edit me-2" id="btn-reload-queue"><i class="bi bi-arrow-clockwise"></i> Reload</button>
            <button type="button" class="btn btn-delete" id="btn-clear-queue"><i class="bi bi-trash"></i> Clear Semua Data</button>
        </div>
    </div>

    <div id="queue-warning" class="alert alert-warning d-none">Queue stalled. Pending masih ada, tetapi worker tidak aktif.</div>

    <div class="row mb-3" id="queue-summary">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Pending</div><div class="fs-4 fw-700" data-key="pending">0</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Processing</div><div class="fs-4 fw-700" data-key="processing">0</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Completed</div><div class="fs-4 fw-700" data-key="completed">0</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Failed</div><div class="fs-4 fw-700" data-key="failed">0</div></div></div></div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-control" id="queue-status-filter">
                        <option value="">Semua Status</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cari</label>
                    <input type="text" class="form-control" id="queue-search" placeholder="Creator, link, platform, error">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Per Halaman</label>
                    <select class="form-control" id="queue-per-page">
                        <option value="10">10</option>
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="queue-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="queue-check-all"></th>
                            <th>ID</th>
                            <th>Campaign</th>
                            <th>Creator</th>
                            <th>Platform</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Error</th>
                            <th>Created</th>
                            <th style="width:220px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <button type="button" class="btn btn-primary mt-2" id="btn-retry-selected">Retry Gagal Terpilih</button>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="text-muted small" id="queue-page-info">-</div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-edit" id="queue-prev-page">Sebelumnya</button>
                    <button type="button" class="btn btn-edit" id="queue-next-page">Berikutnya</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="queue-history-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Riwayat Queue</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="queue-history-body">Loading...</div>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        var queueConfig = {
            queueDataUrl: "<?= base_url() ?>endorse/queue-data",
            queueHistoryUrl: "<?= base_url() ?>endorse/queue-history",
            queueRetryUrl: "<?= base_url() ?>endorse/force-retry",
            queueClearUrl: "<?= base_url() ?>endorse/clear-queue",
            idCampaign: "<?= intval($id_campaign ?? 0) ?>"
        };
        var queueState = {
            page: 1,
            perPage: 20,
            status: '',
            search: '',
            pagination: null
        };

        function selectedIds() {
            var ids = [];
            $(".queue-check:checked").each(function() {
                ids.push($(this).val());
            });
            return ids;
        }

        function renderSummary(summary, health) {
            summary = summary || {};
            $("#queue-summary [data-key='pending']").text(summary.pending || 0);
            $("#queue-summary [data-key='processing']").text(summary.processing || 0);
            $("#queue-summary [data-key='completed']").text(summary.completed || 0);
            $("#queue-summary [data-key='failed']").text(summary.failed || 0);
            $("#queue-warning").toggleClass('d-none', !(health && health.is_stalled));
        }

        function renderRows(rows) {
            var tbody = $("#queue-table tbody");
            tbody.html('');
            if (!rows || !rows.length) {
                tbody.html('<tr><td colspan="10" class="text-center text-muted">Belum ada data antrian.</td></tr>');
                return;
            }

            rows.forEach(function(row) {
                var actions = '<button type="button" class="btn btn-sm btn-edit queue-history" data-id="' + row.id + '">Riwayat</button>';
                if (row.redirect_url) {
                    actions += ' <a href="' + row.redirect_url + '" class="btn btn-sm btn-sync">Lihat Konten</a>';
                }
                if (row.status === 'failed') {
                    actions += ' <button type="button" class="btn btn-sm btn-primary queue-retry" data-id="' + row.id + '">Retry</button>';
                }
                tbody.append(
                    '<tr>' +
                        '<td><input type="checkbox" class="queue-check" value="' + row.id + '"' + (row.status === 'failed' ? '' : ' disabled') + '></td>' +
                        '<td>#' + row.id + '<br><small class="text-muted">endorse #' + row.id_endorse + '</small></td>' +
                        '<td>' + (row.id_campaign || '-') + '</td>' +
                        '<td>' + (row.nama_creator || '-') + '<br><small class="text-muted">' + (row.task || '-') + '</small></td>' +
                        '<td>' + (row.platform || '-') + '</td>' +
                        '<td><span class="badge bg-secondary">' + (row.status || '-') + '</span></td>' +
                        '<td>' + (row.attempts || 0) + '/' + (row.max_attempts || 0) + '</td>' +
                        '<td>' + (row.error_message || '-') + '</td>' +
                        '<td>' + (row.created_at || '-') + '</td>' +
                        '<td>' + actions + '</td>' +
                    '</tr>'
                );
            });
        }

        function renderPagination(pagination, total) {
            queueState.pagination = pagination || null;
            if (!pagination) {
                $("#queue-page-info").text('-');
                return;
            }

            $("#queue-page-info").text(
                'Halaman ' + pagination.current_page + ' / ' + pagination.total_pages + ' • ' + (total || 0) + ' data'
            );
            $("#queue-prev-page").prop('disabled', !pagination.has_prev);
            $("#queue-next-page").prop('disabled', !pagination.has_next);
        }

        function loadQueue() {
            $.getJSON(queueConfig.queueDataUrl, {
                page: queueState.page,
                length: queueState.perPage,
                per_page: queueState.perPage,
                id_campaign: queueConfig.idCampaign,
                status: queueState.status,
                search: { value: queueState.search }
            }).done(function(response) {
                renderSummary(response.summary, response.health);
                renderRows(response.data || []);
                renderPagination(response.pagination, response.recordsFiltered);
            });
        }

        function showHistory(id) {
            $("#queue-history-body").html('Loading...');
            $("#queue-history-modal").modal('show');
            $.getJSON(queueConfig.queueHistoryUrl, { id: id }).done(function(response) {
                var html = '<table class="table table-bordered"><thead><tr><th>Attempt</th><th>Status</th><th>Error Class</th><th>Error</th><th>Started</th><th>Finished</th></tr></thead><tbody>';
                if (!response.data || !response.data.length) {
                    html += '<tr><td colspan="6" class="text-center text-muted">Belum ada riwayat.</td></tr>';
                } else {
                    response.data.forEach(function(row) {
                        html += '<tr><td>' + row.attempt_no + '</td><td>' + row.status + '</td><td>' + (row.error_class || '-') + '</td><td>' + (row.error_message || '-') + '</td><td>' + (row.started_at || '-') + '</td><td>' + (row.finished_at || '-') + '</td></tr>';
                    });
                }
                html += '</tbody></table>';
                $("#queue-history-body").html(html);
            });
        }

        $(document).on('click', '.queue-history', function() {
            showHistory($(this).data('id'));
        });

        $(document).on('click', '.queue-retry', function() {
            $.post(queueConfig.queueRetryUrl, { ids: String($(this).data('id')) }, function(response) {
                alert((response && response.msg) ? response.msg : 'Data gagal dimasukkan ulang ke antrian.');
                loadQueue();
            }, 'json');
        });

        $("#btn-retry-selected").on('click', function() {
            var ids = selectedIds();
            if (!ids.length) {
                return;
            }
            $.post(queueConfig.queueRetryUrl, { ids: ids.join(',') }, function(response) {
                alert((response && response.msg) ? response.msg : 'Data gagal dimasukkan ulang ke antrian.');
                loadQueue();
            }, 'json');
        });

        $("#btn-clear-queue").on('click', function() {
            if (!window.confirm('Hapus seluruh queue dan histori?')) {
                return;
            }
            $.post(queueConfig.queueClearUrl, {}, function(response) {
                alert((response && response.msg) ? response.msg : 'Queue berhasil dibersihkan.');
                queueState.page = 1;
                loadQueue();
            }, 'json');
        });

        $("#btn-reload-queue").on('click', loadQueue);
        $("#queue-status-filter").on('change', function() {
            queueState.status = $(this).val();
            queueState.page = 1;
            loadQueue();
        });
        $("#queue-per-page").on('change', function() {
            queueState.perPage = parseInt($(this).val(), 10) || 20;
            queueState.page = 1;
            loadQueue();
        });
        $("#queue-search").on('keypress', function(e) {
            if (e.which === 13) {
                queueState.search = $(this).val().trim();
                queueState.page = 1;
                loadQueue();
            }
        });
        $("#queue-prev-page").on('click', function() {
            if (queueState.page > 1) {
                queueState.page -= 1;
                loadQueue();
            }
        });
        $("#queue-next-page").on('click', function() {
            if (queueState.pagination && queueState.pagination.has_next) {
                queueState.page += 1;
                loadQueue();
            }
        });
        $("#queue-check-all").on('change', function() {
            $(".queue-check:not(:disabled)").prop('checked', $(this).is(':checked'));
        });

        loadQueue();
        setInterval(loadQueue, 15000);
    })();
</script>
