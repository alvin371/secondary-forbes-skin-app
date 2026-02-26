<div class="form-message"></div>
<form action="<?= base_url() ?>/endorse/sync-all-start" method="POST" id="form-modal-sync-all">
	<input type="hidden" name="id" value="<?= $data['id'] ?>">
	<input type="hidden" name="mode" value="<?= htmlspecialchars($data['mode'] ?? '', ENT_QUOTES) ?>">
	<input type="hidden" name="ids" value="<?= htmlspecialchars($data['ids'] ?? '', ENT_QUOTES) ?>">
	<p>Apakah kamu yakin ingin melakukan refresh data?</p>
	<div class="progress mb-2 d-none" id="sync-progress-wrapper" style="height: 22px;">
		<div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" id="sync-progress-bar" style="width:0%">0%</div>
	</div>
	<div class="small text-muted d-none mb-2" id="sync-progress-text">Menyiapkan job...</div>
	<div class="col-md-12 mt-3">
		<button type="submit" class="btn btn-primary btn-send">Refresh Data</button>
	</div>
</form>
<script type="text/javascript">
	(function() {
		var syncConfig = {
			batchSize: 3,
			maxRetries: 2,
			retryDelayMs: 2000,
			interBatchDelayMs: 250,
			requestTimeoutMs: 90000
		};
		var syncState = {
			running: false,
			jobId: null,
			totalTarget: 0
		};

		function setButtonState(isLoading, text) {
			var label = text || 'Refresh Data';
			if (isLoading) {
				$(".btn-send").addClass("disabled").html('<div class="loading-ellipsis"><div></div><div></div><div></div><div></div></div>').attr('disabled', true);
				return;
			}
			$(".btn-send").removeClass("disabled").text(label).attr('disabled', false);
		}

		function showProgress() {
			$("#sync-progress-wrapper, #sync-progress-text").removeClass("d-none");
		}

		function updateProgress(processed, total, progress) {
			var safeTotal = parseInt(total || 0, 10);
			var safeProcessed = parseInt(processed || 0, 10);
			var safeProgress = Math.max(0, Math.min(100, parseInt(progress || 0, 10)));
			$("#sync-progress-bar").css("width", safeProgress + "%").text(safeProgress + "%");
			$("#sync-progress-text").text("Processing... (" + safeProcessed + "/" + safeTotal + ")");
		}

		function finalizeSync(isSuccess, message) {
			syncState.running = false;
			setButtonState(false, 'Refresh Data');

			var alertClass = isSuccess ? 'alert-success' : 'alert-danger';
			$(".form-message").hide().html('<div class="alert ' + alertClass + ' mb-2">' + message + '</div>').slideDown("fast");

			if (isSuccess) {
				if (typeof loadMoreData === 'function') {
					loadMoreData();
				}
				if (typeof get_chart === 'function') {
					get_chart();
				}
				setTimeout(function() {
					$("#modal-form").modal('hide');
				}, 900);
			}
		}

		function parseAjaxError(jqXHR, textStatus, errorThrown) {
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
				return jqXHR.responseJSON.message;
			}
			if (textStatus === 'timeout') {
				return 'Request timeout. Silakan ulangi refresh data.';
			}
			return errorThrown || 'Terjadi kesalahan koneksi ke server.';
		}

		function runChunk(retryAttempt) {
			if (!syncState.jobId) {
				finalizeSync(false, 'Job sinkronisasi tidak ditemukan.');
				return;
			}

			$.ajax({
				type: "POST",
				url: "<?= base_url() ?>/endorse/sync-all-process",
				dataType: "json",
				timeout: syncConfig.requestTimeoutMs,
				data: {
					job_id: syncState.jobId,
					batch_size: syncConfig.batchSize
				}
			}).done(function(response) {
				if (!response || response.status !== 'success') {
					finalizeSync(false, (response && response.message) ? response.message : 'Response tidak valid dari server.');
					return;
				}

				syncState.totalTarget = parseInt(response.total_target || syncState.totalTarget || 0, 10);
				updateProgress(response.processed_total || 0, syncState.totalTarget, response.progress || 0);

				if (response.has_more) {
					setTimeout(function() {
						runChunk(0);
					}, syncConfig.interBatchDelayMs);
					return;
				}

				var warningText = '';
				if (parseInt(response.error_count || 0, 10) > 0) {
					warningText = ' (' + response.error_count + ' data gagal diambil otomatis, gunakan refresh ulang bila perlu)';
				}
				finalizeSync(true, (response.message || 'Refresh data selesai.') + warningText);
			}).fail(function(jqXHR, textStatus, errorThrown) {
				if (retryAttempt < syncConfig.maxRetries) {
					$("#sync-progress-text").text('Koneksi lambat, retry ' + (retryAttempt + 1) + '/' + syncConfig.maxRetries + '...');
					setTimeout(function() {
						runChunk(retryAttempt + 1);
					}, syncConfig.retryDelayMs);
					return;
				}
				finalizeSync(false, parseAjaxError(jqXHR, textStatus, errorThrown));
			});
		}

		$(document).off('submit', '#form-modal-sync-all').on('submit', '#form-modal-sync-all', function(e) {
			e.preventDefault();
			if (syncState.running) {
				return false;
			}

			var form = $(this);
			var mydata = new FormData(this);
			syncState.running = true;
			syncState.jobId = null;
			syncState.totalTarget = 0;

			showProgress();
			updateProgress(0, 0, 0);
			$("#sync-progress-text").text('Menyiapkan job...');
			setButtonState(true);
			$(".form-message").slideUp().html("");

			$.ajax({
				type: "POST",
				url: form.attr("action"),
				data: mydata,
				cache: false,
				contentType: false,
				processData: false,
				dataType: "json",
				timeout: 20000
			}).done(function(response) {
				if (!response || response.status !== 'success') {
					finalizeSync(false, (response && response.message) ? response.message : 'Gagal memulai sinkronisasi.');
					return;
				}

				if (!response.job_id || parseInt(response.total_target || 0, 10) <= 0) {
					finalizeSync(true, response.message || 'Tidak ada data untuk direfresh.');
					return;
				}

				syncState.jobId = response.job_id;
				syncState.totalTarget = parseInt(response.total_target || 0, 10);
				if (parseInt(response.batch_size_default || 0, 10) > 0) {
					syncConfig.batchSize = parseInt(response.batch_size_default, 10);
				}
				updateProgress(0, syncState.totalTarget, 0);
				runChunk(0);
			}).fail(function(jqXHR, textStatus, errorThrown) {
				finalizeSync(false, parseAjaxError(jqXHR, textStatus, errorThrown));
			});

			return false;
		});
	})();
</script>
