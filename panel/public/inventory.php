<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Envanter', 'inventory.php');
?>
<div data-page="inventory">
  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <input id="inv-search" type="search" class="form-control w-auto" placeholder="Ad, seri no, model, işlemci ara">
      <span class="text-secondary ms-auto" id="inv-count"></span>
      <a class="btn btn-primary" href="admin_api.php?action=inventory_csv"><i class="ti ti-download me-1"></i>CSV indir</a>
    </div>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Cihaz</th><th>Lokasyon</th><th>Seri No</th><th>Model</th><th>İşlemci</th><th>RAM</th><th>İşletim Sistemi</th><th>Yazılım</th></tr></thead>
        <tbody id="inv-rows"><tr class="empty-row"><td colspan="8">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
  <p class="text-secondary small mt-2">Envanter cihaz açık olduğunda, ilk kurulumda ve sonra 12 saatte bir güncellenir.</p>
</div>
<?php page_footer();
