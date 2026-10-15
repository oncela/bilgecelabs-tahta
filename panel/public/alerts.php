<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Uyarılar', 'alerts.php');
?>
<div data-page="alerts">
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Açık uyarılar</h3><span class="badge ms-auto bg-secondary-lt" id="open-count"></span></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Önem</th><th>Cihaz</th><th>Uyarı</th><th>Başlangıç</th><th></th></tr></thead>
        <tbody id="open-rows"><tr class="empty-row"><td colspan="5">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Son çözülenler</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Önem</th><th>Cihaz</th><th>Uyarı</th><th>Çözüldü</th></tr></thead>
        <tbody id="resolved-rows"><tr class="empty-row"><td colspan="4">—</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
<?php page_footer();
