<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Destek talepleri', 'tickets.php');
?>
<div data-page="tickets">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Destek talepleri</h3>
      <span class="badge ms-auto bg-secondary-lt" id="tk-count"></span>
    </div>
    <div class="card-body border-bottom py-2 text-secondary small">
      Kullanıcılar cihazdaki <b>BilgeceLabs Destek</b> penceresinden (saatin yanındaki simge) talep açar.
      Yazdığınız yanıt aynı pencerede görünür.
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>#</th><th>Durum</th><th>Cihaz</th><th>Kullanıcı</th><th>Konu</th><th>Tarih</th><th></th></tr></thead>
        <tbody id="tk-rows"><tr class="empty-row"><td colspan="7">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<dialog id="tk-dialog" class="aci-dialog">
  <form method="dialog" id="tk-form">
    <h3 class="mb-1" id="tk-title"></h3>
    <div class="text-secondary small mb-3" id="tk-meta"></div>
    <div class="mb-3 p-3 bg-light rounded" id="tk-message" style="white-space:pre-wrap"></div>
    <label class="form-label" for="tk-reply">Yanıt (kullanıcı destek penceresinde görür)</label>
    <textarea class="form-control mb-3" id="tk-reply" rows="4" maxlength="4000"></textarea>
    <label class="form-check mb-3"><input class="form-check-input" type="checkbox" id="tk-close"><span class="form-check-label">Talebi kapat</span></label>
    <div class="d-flex justify-content-end gap-2">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn btn-primary" value="save">Kaydet</button>
    </div>
  </form>
</dialog>
<?php page_footer();
