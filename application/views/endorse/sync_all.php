<div class="form-message"></div>
<form action="<?= base_url() ?>/endorse/sync-all-start" method="POST" id="form-modal-sync-all">
	<input type="hidden" name="id" value="<?= $data['id'] ?>">
	<input type="hidden" name="id_campaign" value="<?= $data['id'] ?>">
	<input type="hidden" name="mode" value="<?= htmlspecialchars($data['mode'] ?? '', ENT_QUOTES) ?>">
	<input type="hidden" name="ids" value="<?= htmlspecialchars($data['ids'] ?? '', ENT_QUOTES) ?>">
	<p>Apakah kamu yakin ingin melakukan refresh data?</p>
	<div class="col-md-12 mt-3">
		<button type="submit" class="btn btn-primary btn-send">Refresh Data</button>
	</div>
</form>
<script type="text/javascript">
	(function() {
		function setButtonState(isLoading, text) {
			var label = text || 'Refresh Data';
			if (isLoading) {
				$(".btn-send").addClass("disabled").html('<div class="loading-ellipsis"><div></div><div></div><div></div><div></div></div>').attr('disabled', true);
				return;
			}
			$(".btn-send").removeClass("disabled").text(label).attr('disabled', false);
		}

		function finalizeSync(isSuccess, message) {
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

		$(document).off('submit', '#form-modal-sync-all').on('submit', '#form-modal-sync-all', function(e) {
			e.preventDefault();

			var form = $(this);
			var mydata = new FormData(this);
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
				if (!response || response.status !== true) {
					finalizeSync(false, (response && response.msg) ? response.msg : 'Gagal menambahkan antrian refresh.');
					return;
				}

				var queueUrl = "<?= base_url() ?>/endorse/queue?id_campaign=<?= intval($data['id']) ?>";
				var message = (response.msg || 'Refresh data ditambahkan ke antrian.') + ' <a href="' + queueUrl + '">Lihat antrian</a>';
				finalizeSync(true, message);
			}).fail(function(jqXHR, textStatus, errorThrown) {
				finalizeSync(false, parseAjaxError(jqXHR, textStatus, errorThrown));
			});

			return false;
		});
	})();
</script>
