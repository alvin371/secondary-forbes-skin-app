<div class="form-message"></div>
<form action="<?= base_url() ?>/endorse/update-stats" method="POST" id="form-edit-stats">
    <input type="hidden" name="id" value="<?= (int) $data['id'] ?>">

    <p class="mb-3">Perbarui statistik manual untuk konten ini.</p>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="stats-views" class="form-label">Views</label>
            <input id="stats-views" type="number" min="0" step="1" class="form-control" name="dt[views]" value="<?= (int) ($data['views'] ?? 0) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="stats-likes" class="form-label">Likes</label>
            <input id="stats-likes" type="number" min="0" step="1" class="form-control" name="dt[likes]" value="<?= (int) ($data['likes'] ?? 0) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="stats-comment" class="form-label">Comments</label>
            <input id="stats-comment" type="number" min="0" step="1" class="form-control" name="dt[comment]" value="<?= (int) ($data['comment'] ?? 0) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label for="stats-share-save" class="form-label">Save &amp; Share</label>
            <input id="stats-share-save" type="number" min="0" step="1" class="form-control" name="dt[share_save]" value="<?= (int) ($data['share_save'] ?? 0) ?>" required>
        </div>
    </div>

    <div class="col-md-12 mt-2">
        <button type="submit" class="btn btn-primary btn-send">Simpan Statistik</button>
    </div>
</form>

<script type="text/javascript">
    (function() {
        var $form = $("#form-edit-stats");
        if (!$form.length) {
            return;
        }

        $form.on("submit", function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            var $button = $form.find(".btn-send");
            var $message = $form.prev(".form-message");

            $.ajax({
                type: "POST",
                url: $form.attr("action"),
                data: formData,
                cache: false,
                contentType: false,
                processData: false,
                dataType: "json",
                beforeSend: function() {
                    $button.addClass("disabled").text("Menyimpan...").attr("disabled", true);
                    $message.stop(true, true).hide().html("");
                },
                success: function(response) {
                    if (response && response.status) {
                        $message
                            .removeClass("alert-danger")
                            .addClass("alert alert-success")
                            .html(response.message || "Statistik berhasil diperbarui.")
                            .slideDown("fast");

                        setTimeout(function() {
                            $("#modal-form").modal("hide");
                            $button.removeClass("disabled").text("Simpan Statistik").attr("disabled", false);
                            if (typeof loadMoreData === "function") {
                                loadMoreData();
                            }
                        }, 500);
                        return;
                    }

                    $message
                        .removeClass("alert-success")
                        .addClass("alert alert-danger")
                        .html((response && response.message) ? response.message : "Gagal memperbarui statistik.")
                        .slideDown("fast");
                    $button.removeClass("disabled").text("Simpan Statistik").attr("disabled", false);
                },
                error: function(xhr) {
                    var text = "Terjadi kesalahan saat menyimpan statistik.";
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        text = xhr.responseJSON.message;
                    }
                    $message
                        .removeClass("alert-success")
                        .addClass("alert alert-danger")
                        .html(text)
                        .slideDown("fast");
                    $button.removeClass("disabled").text("Simpan Statistik").attr("disabled", false);
                }
            });
        });
    })();
</script>
