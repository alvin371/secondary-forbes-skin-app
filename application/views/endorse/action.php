<div class="form-message"></div>
<form action="<?= base_url() ?>/endorse/action-process" method="POST" id="form-action">
	<input type="hidden" name="id_campaign" value="<?= $id_campaign ?>">
	<input type="hidden" name="code" value="<?= $data['code'] ?>">
	<p><?= $question ?></p>
	<?php if ($data['code'] == "ubah_status") {
		$arr = array();
		$arr[] = "Review";
		$arr[] = "Hold";
		$arr[] = "Acc";
		$arr[] = "Draft Content";
		$arr[] = "Posted Content";
		$arr[] = "Reject";
		$arr[] = "Problem";
	?>
		<select name="status" class="form-control" id="">
			<?php foreach ($arr as $k => $v) { ?>
				<option value="<?= $v ?>"><?= $v ?></option>
			<?php } ?>
		</select>
	<?php } ?>
	<?php if ($data['code'] == "ubah_status_data") {
		$arr = array();
		$arr[] = "Aktif";
		$arr[] = "Tidak Aktif";
	?>
		<select name="status" class="form-control" id="">
			<?php foreach ($arr as $k => $v) { ?>
				<option value="<?= $v ?>"><?= $v ?></option>
			<?php } ?>
		</select>
	<?php } ?>
	<?php if ($data['code'] == "ubah_status_payment") {
		$btn = 'Ajukan Payment';
		$arr = array();
		// $arr[] = "Pengajuan Payment";
		// $arr[] = "DP";
		$arr[] = "FP";
	?>
		<select name="status" class="form-control" id="">
			<?php foreach ($arr as $k => $v) { ?>
				<option value="<?= $v ?>"><?= $v ?></option>
			<?php } ?>
		</select>
	<?php } ?>
	<div class="col-md-12 mt-3">
		<button type="submit" class="btn btn-primary btn-send"><?= $btn ?></button>
	</div>
</form>
<script type="text/javascript">
	$("#form-action").submit(function() {
		var form = $(this);
		var mydata = new FormData(this);
		$.ajax({
			type: "POST",
			url: form.attr("action"),
			data: mydata,
			cache: false,
			contentType: false,
			processData: false,
			beforeSend: function() {
				$(".btn-send").addClass("disabled").html('<div class="loading-ellipsis"><div></div><div></div><div></div><div></div></div>').attr('disabled', true);
				form.find(".form-message").slideUp().html("");
			},
			success: function(response, textStatus, xhr) {
				var jsonResponse = null;
				if (typeof response === 'object' && response !== null) {
					jsonResponse = response;
				} else if (typeof response === 'string') {
					try {
						jsonResponse = JSON.parse(response);
					} catch (e) {}
				}

				if (jsonResponse) {
					var isSuccess = jsonResponse.status === true;
					var queueUrl = "<?= base_url() ?>endorse/queue?id_campaign=<?= intval($id_campaign) ?>";
					var message = jsonResponse.msg || 'Proses selesai.';
					if (isSuccess && $("input[name='code']").val() === 'refresh_data') {
						message += ' <a href="' + queueUrl + '">Lihat antrian</a>';
					}
					var klass = isSuccess ? 'success' : 'danger';
					$(".form-message").hide().html('<div class="alert alert-' + klass + '">' + message + '</div>').slideDown("fast");
					$(".btn-send").removeClass("disabled").html('<?= $btn ?>').attr('disabled', false);
					if (isSuccess) {
						setTimeout(function() {
							window.location.href = "";
						}, 1800);
					}
					return;
				}
				var str = response;
				console.log(str);
				if (str.indexOf("success") != -1) {
					$(".form-message").hide().html(response).slideDown("fast");
					setTimeout(function() {
						window.location.href = "";
						$(".btn-send").removeClass("disabled").html('<?= $btn ?>').attr('disabled', false);
					}, 2500);
				} else {
					$(".form-message").hide().html(response).slideDown("fast");
					$(".btn-send").removeClass("disabled").html('<?= $btn ?>').attr('disabled', false);
				}
			},
			error: function(xhr, textStatus, errorThrown) {
				$(".btn-send").removeClass("disabled").html('<?= $btn ?>').attr('disabled', false);
				$(".form-message").hide().html(xhr).slideDown("fast");
			}
		});
		return false;
	});
</script>
