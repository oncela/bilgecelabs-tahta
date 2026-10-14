<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Tahtalar', 'devices.php');
?>
<div data-page="devices">
  <div class="head">
    <h1>Tahtalar</h1>
    <div class="counts" id="counts"></div>
  </div>

  <div class="toolbar card">
    <div class="filters">
      <label for="room-filter" class="sr">Sınıf</label>
      <select id="room-filter"><option value="">Tüm sınıflar</option></select>
      <label for="search" class="sr">Ara</label>
      <input id="search" type="search" placeholder="Tahta, kullanıcı veya IP ara">
    </div>
    <div class="actions">
      <span class="selcount" id="selcount">0 tahta seçili</span>
      <button class="btn" data-cmd="message">Mesaj</button>
      <button class="btn" data-cmd="restart">Yeniden başlat</button>
      <button class="btn" data-cmd="shutdown">Kapat</button>
      <button class="btn" data-cmd="cancel_shutdown">Kapatmayı iptal</button>
      <button class="btn" data-cmd="defender_scan">Virüs taraması</button>
      <button class="btn" data-cmd="defender_update">İmza güncelle</button>
      <button class="btn" data-cmd="powershell">PowerShell</button>
    </div>
  </div>

  <div class="table-wrap card">
    <table class="grid">
      <thead><tr>
        <th class="chk"><input type="checkbox" id="select-all" aria-label="Görünen tüm tahtaları seç"></th>
        <th>Tahta</th><th>Sınıf</th><th>Durum</th><th>Oturum</th><th>Virüs koruması</th><th>IP</th><th>Açık kalma</th>
      </tr></thead>
      <tbody id="rows"><tr><td colspan="8" class="empty">Yükleniyor…</td></tr></tbody>
    </table>
  </div>
</div>

<dialog id="cmd-dialog">
  <form method="dialog" id="cmd-form">
    <h2 id="cmd-title"></h2>
    <p class="muted" id="cmd-target"></p>
    <div id="cmd-fields"></div>
    <p class="alert" id="cmd-error" hidden></p>
    <div class="dlg-actions">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn primary" id="cmd-submit">Gönder</button>
    </div>
  </form>
</dialog>

<dialog id="result-dialog" class="wide">
  <h2>Sonuçlar</h2>
  <p class="muted" id="result-summary"></p>
  <div id="result-list" class="results"></div>
  <div class="dlg-actions"><button type="button" class="btn" data-close>Kapat</button></div>
</dialog>

<dialog id="device-dialog" class="wide">
  <form id="device-form">
    <h2 id="dev-title"></h2>
    <div class="remote-links" id="dev-remote">
      <a class="btn primary" data-view="desktop">Ekranı aç</a>
      <a class="btn" data-view="files">Dosyalar</a>
      <a class="btn" data-view="terminal">Komut satırı</a>
    </div>
    <dl class="facts" id="dev-facts"></dl>
    <div class="row2">
      <div><label for="dev-name">Ad</label><input id="dev-name" maxlength="128" placeholder="ör. 7-B Tahta"></div>
      <div><label for="dev-room">Sınıf</label><input id="dev-room" maxlength="64" list="room-list" placeholder="ör. 7-B"></div>
    </div>
    <datalist id="room-list"></datalist>
    <h3>Son işlemler</h3>
    <div id="dev-history" class="results"></div>
    <div class="dlg-actions">
      <button type="button" class="btn danger" id="dev-delete">Panelden sil</button>
      <span class="spacer"></span>
      <button type="button" class="btn" data-close>Kapat</button>
      <button type="submit" class="btn primary">Kaydet</button>
    </div>
  </form>
</dialog>
<?php page_footer();
