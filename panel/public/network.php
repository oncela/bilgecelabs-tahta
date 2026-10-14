<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Ağ', 'network.php');
?>
<div data-page="network">
  <div class="head">
    <h1>Okul ağındaki cihazlar</h1>
    <div class="counts" id="net-counts"></div>
  </div>
  <div class="toolbar card">
    <p class="muted" id="last-scan">Henüz tarama yapılmadı.</p>
    <div class="actions">
      <label class="inline"><input type="checkbox" id="only-new"> Sadece yeni cihazlar</label>
      <button class="btn primary" id="scan-btn">Ağı şimdi tara</button>
    </div>
  </div>
  <div class="table-wrap card">
    <table class="grid">
      <thead><tr><th>Cihaz</th><th>IP</th><th>MAC</th><th>Tür</th><th>İlk görülme</th><th>Son görülme</th><th></th></tr></thead>
      <tbody id="net-rows"><tr><td colspan="7" class="empty">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
  <p class="muted small">Tarama, o an çevrimiçi olan bir tahta tarafından yapılır ve tahtanın bağlı olduğu yerel ağı kapsar. Telefonlar enerji tasarrufu nedeniyle her taramada görünmeyebilir.</p>
</div>

<dialog id="net-dialog">
  <form id="net-form">
    <h2>Cihazı adlandır</h2>
    <p class="muted" id="net-info"></p>
    <label for="net-label">Ad</label>
    <input id="net-label" maxlength="128" placeholder="ör. Müdür odası yazıcı">
    <label class="inline"><input type="checkbox" id="net-known"> Tanıdık cihaz (yeni cihaz uyarısı verme)</label>
    <div class="dlg-actions">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn primary">Kaydet</button>
    </div>
  </form>
</dialog>
<?php page_footer();
