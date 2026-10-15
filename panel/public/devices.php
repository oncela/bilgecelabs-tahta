<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Cihazlar', 'devices.php');
?>
<div data-page="devices">
  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <select id="loc-filter" class="form-select w-auto" aria-label="Lokasyon"><option value="">Tüm lokasyonlar</option></select>
      <select id="type-filter" class="form-select w-auto" aria-label="Tür"><option value="">Tüm türler</option><option value="board">Akıllı tahta</option><option value="classroom">Sınıf bilgisayarı</option><option value="office">İdare</option><option value="desk">Danışma</option><option value="other">Diğer</option></select>
      <select id="room-filter" class="form-select w-auto" aria-label="Sınıf"><option value="">Tüm sınıflar</option></select>
      <input id="search" type="search" class="form-control w-auto" placeholder="Cihaz, kullanıcı veya IP ara" aria-label="Ara" value="<?= h($_GET['q'] ?? '') ?>">
      <span class="text-secondary ms-auto" id="counts"></span>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr>
          <th class="w-1"><input type="checkbox" class="form-check-input" id="select-all" aria-label="Görünen tüm tahtaları seç"></th>
          <th>Cihaz</th><th>Lokasyon</th><th>Tür</th><th>Departman</th><th>Durum</th><th>Oturum</th><th>Virüs koruması</th><th>Uzaktan erişim</th><th>IP</th><th>Açık kalma</th>
        </tr></thead>
        <tbody id="rows"><tr class="empty-row"><td colspan="11">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

  <!-- Seçim çubuğu: cihaz seçilince altta belirir -->
  <div class="selbar" id="selbar" hidden role="toolbar" aria-label="Seçili cihazlara işlem">
    <div class="selbar-count"><strong id="selcount">0</strong> cihaz seçili
      <button type="button" class="selbar-clear" id="sel-clear" title="Seçimi temizle"><i class="ti ti-x"></i></button></div>
    <div class="selbar-group">
      <button class="btn btn-sm" data-cmd="message"><i class="ti ti-message"></i>Mesaj</button>
      <button class="btn btn-sm" data-cmd="run_app"><i class="ti ti-app-window"></i>Uygulama</button></div>
    <div class="selbar-group">
      <button class="btn btn-sm" data-cmd="restart"><i class="ti ti-refresh"></i>Yeniden başlat</button>
      <button class="btn btn-sm" data-cmd="shutdown"><i class="ti ti-power"></i>Kapat</button>
      <button class="btn btn-sm" data-cmd="wake"><i class="ti ti-player-play"></i>Uzaktan aç</button></div>
    <div class="selbar-group">
      <button class="btn btn-sm" data-cmd="defender_scan"><i class="ti ti-shield-search"></i>Virüs taraması</button>
      <button class="btn btn-sm" id="assign-loc-btn"><i class="ti ti-building"></i>Lokasyon ata</button>
      <div class="dropup">
        <button class="btn btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ti ti-dots"></i>Diğer</button>
        <div class="dropdown-menu dropdown-menu-end">
          <button class="dropdown-item" data-cmd="toast"><i class="ti ti-bell me-2"></i>Bildirim göster</button>
          <button class="dropdown-item" data-cmd="open_url"><i class="ti ti-world me-2"></i>Web adresi aç</button>
          <div class="dropdown-divider"></div>
          <button class="dropdown-item" data-cmd="cancel_shutdown"><i class="ti ti-x me-2"></i>Kapatmayı iptal et</button>
          <button class="dropdown-item" data-cmd="wol_prepare"><i class="ti ti-plug-connected me-2"></i>Uzaktan açmaya hazırla</button>
          <button class="dropdown-item" data-cmd="defender_update"><i class="ti ti-shield-up me-2"></i>Virüs imzalarını güncelle</button>
          <div class="dropdown-divider"></div>
          <button class="dropdown-item" data-cmd="powershell"><i class="ti ti-terminal-2 me-2"></i>PowerShell komutu</button>
        </div>
      </div></div>
  </div>

<?php require __DIR__ . '/../views/dialogs.php'; ?>

<dialog id="assign-dialog" class="aci-dialog">
  <form id="assign-form" class="card">
    <div class="card-header"><h3 class="card-title">Lokasyon ata</h3></div>
    <div class="card-body">
      <p class="text-secondary" id="assign-count"></p>
      <label class="form-label" for="assign-select">Lokasyon</label>
      <select class="form-select" id="assign-select"></select>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn btn-primary">Ata</button>
    </div>
  </form>
</dialog>

<dialog id="device-dialog" class="aci-dialog wide">
  <form id="device-form" class="card">
    <div class="card-header"><h3 class="card-title" id="dev-title"></h3></div>
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 mb-3" id="dev-remote">
        <a class="btn btn-primary" data-view="desktop"><i class="ti ti-device-desktop me-1"></i>Ekranı aç</a>
        <a class="btn" data-view="files"><i class="ti ti-folder me-1"></i>Dosyalar</a>
        <a class="btn" data-view="terminal"><i class="ti ti-terminal-2 me-1"></i>Komut satırı</a>
      </div>
      <dl class="row mb-3" id="dev-facts"></dl>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="dev-location">Lokasyon / şube</label>
          <select id="dev-location" class="form-select"><option value="">—</option></select></div>
        <div class="col-md-6"><label class="form-label" for="dev-type">Tür</label>
          <select id="dev-type" class="form-select">
            <option value="board">Akıllı tahta</option>
            <option value="classroom">Sınıf bilgisayarı</option>
            <option value="office">İdare</option>
            <option value="desk">Danışma</option>
            <option value="other">Diğer</option>
          </select></div>
        <div class="col-md-6"><label class="form-label" for="dev-name">Ad</label><input id="dev-name" class="form-control" maxlength="128" placeholder="7-B Tahta"></div>
        <div class="col-md-6"><label class="form-label" for="dev-room">Departman / konum</label><input id="dev-room" class="form-control" maxlength="64" list="room-list" placeholder="7-B"></div>
      </div>
      <datalist id="room-list"></datalist>
      <h4 class="mt-4 mb-2">Son işlemler</h4>
      <div id="dev-history" class="d-grid gap-2"></div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button type="button" class="btn btn-ghost-danger" id="dev-uninstall"><i class="ti ti-trash me-1"></i>Tahtadan kaldır</button>
      <button type="button" class="btn btn-ghost-danger" id="dev-delete">Panelden sil</button>
      <span class="ms-auto"></span>
      <button type="button" class="btn" data-close>Kapat</button>
      <button type="submit" class="btn btn-primary">Kaydet</button>
    </div>
  </form>
</dialog>
<?php page_footer();
