<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Okul ağındaki cihazlar', 'network.php');
?>
<div data-page="network">
  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
      <span class="text-secondary" id="last-scan">Henüz tarama yapılmadı.</span>
      <span class="text-secondary" id="net-counts"></span>
      <label class="form-check form-switch ms-auto mb-0">
        <input class="form-check-input" type="checkbox" id="only-new">
        <span class="form-check-label">Sadece yeni cihazlar</span>
      </label>
      <button class="btn btn-primary" id="scan-btn"><i class="ti ti-radar me-1"></i>Ağı şimdi tara</button>
    </div>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Cihaz</th><th>IP</th><th>MAC</th><th>Tür</th><th>İlk görülme</th><th>Son görülme</th><th class="w-1"></th></tr></thead>
        <tbody id="net-rows"><tr class="empty-row"><td colspan="7">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
  <p class="text-secondary small mt-3">Tarama o an çevrimiçi olan bir tahta tarafından yapılır ve tahtanın bağlı olduğu yerel ağı kapsar. Telefonlar enerji tasarrufu nedeniyle her taramada görünmeyebilir.</p>
</div>

<dialog id="net-dialog" class="aci-dialog">
  <form id="net-form" class="card">
    <div class="card-header"><h3 class="card-title">Cihazı adlandır</h3></div>
    <div class="card-body">
      <p class="text-secondary" id="net-info"></p>
      <label class="form-label" for="net-label">Ad</label>
      <input id="net-label" class="form-control mb-3" maxlength="128" placeholder="Müdür odası yazıcı">
      <label class="form-check">
        <input class="form-check-input" type="checkbox" id="net-known">
        <span class="form-check-label">Tanıdık cihaz (yeni cihaz uyarısı verme)</span>
      </label>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn btn-primary">Kaydet</button>
    </div>
  </form>
</dialog>
<?php page_footer();
