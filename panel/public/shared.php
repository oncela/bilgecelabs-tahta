<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Ortak alan', 'shared.php');
?>
<div data-page="shared">
  <div class="row row-cards">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Dosya yükle</h3></div>
        <div class="card-body">
          <form id="upload-form">
            <label class="form-label" for="up-file">Dosya</label>
            <input class="form-control mb-3" type="file" id="up-file" required>
            <label class="form-label" for="up-subdir">Alt klasör (isteğe bağlı)</label>
            <input class="form-control mb-1" id="up-subdir" maxlength="64" placeholder="ör. 7-B veya Sunumlar" list="subdir-list">
            <datalist id="subdir-list"></datalist>
            <p class="text-secondary small">Tahtalarda <code>C:\Ortak\&lt;alt klasör&gt;</code> içine iner. Boş bırakılırsa doğrudan <code>C:\Ortak</code>.</p>
            <div class="progress mb-2" id="up-progress" hidden><div class="progress-bar" id="up-bar" style="width:0%"></div></div>
            <div class="alert alert-danger" id="up-error" hidden></div>
            <button type="submit" class="btn btn-primary" id="up-btn"><i class="ti ti-upload me-1"></i>Yükle</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Yüklenen dosyalar</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Dosya</th><th>Boyut</th><th>Klasör</th><th>Tarih</th><th></th></tr></thead>
            <tbody id="shared-rows"><tr class="empty-row"><td colspan="5">Yükleniyor…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<dialog id="send-dialog" class="aci-dialog">
  <form id="send-form" class="card">
    <div class="card-header"><h3 class="card-title">Tahtalara gönder</h3></div>
    <div class="card-body">
      <p class="text-secondary" id="send-info"></p>
      <label class="form-label" for="send-room">Sınıf</label>
      <select class="form-select mb-2" id="send-room"><option value="">Tüm tahtalar</option></select>
      <p class="text-secondary small mb-0" id="send-count"></p>
      <div class="alert alert-danger mt-2 mb-0" id="send-error" hidden></div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn btn-primary">Gönder</button>
    </div>
  </form>
</dialog>
<?php require __DIR__ . '/../views/dialogs.php'; ?>
<?php page_footer();
