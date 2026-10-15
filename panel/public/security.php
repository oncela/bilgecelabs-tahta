<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Güvenlik', 'security.php');
?>
<div data-page="security">
  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <label class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="only-issues"><span class="form-check-label">Sadece sorunlu cihazlar</span></label>
      <span class="text-secondary ms-auto" id="sec-count"></span>
    </div>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Cihaz</th><th>Güvenlik duvarı</th><th>Virüs koruması</th><th>BitLocker</th><th>UAC</th><th>Yeniden başlatma</th><th>Son güncelleme</th></tr></thead>
        <tbody id="sec-rows"><tr class="empty-row"><td colspan="7">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
  <p class="text-secondary small mt-2">Güvenlik karnesi cihaz açıkken saatte bir güncellenir. Kapalı güvenlik duvarı ve (ayarlıysa) BitLocker uyarı oluşturur.</p>
</div>
<?php page_footer();
