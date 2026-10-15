<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Yazılımlar', 'software.php');
?>
<div data-page="software">
  <div class="d-flex mb-3"><button class="btn btn-primary ms-auto" id="cat-new"><i class="ti ti-plus me-1"></i>Program ekle</button></div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Program</th><th>Yöntem</th><th>Kaynak</th><th>Self-servis</th><th></th></tr></thead>
        <tbody id="cat-rows"><tr class="empty-row"><td colspan="5">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
  <p class="text-secondary small mt-2">winget: Windows paket yöneticisiyle kurar (ör. <code>Microsoft.Office</code>, <code>Adobe.Acrobat.Reader.64-bit</code>, <code>RARLab.WinRAR</code>). URL: MSI/EXE adresi. "Self-servis" açık olanları kullanıcı kendi bilgisayarına kurabilir.</p>
</div>

<dialog id="cat-dialog" class="aci-dialog">
  <form id="cat-form" class="card">
    <div class="card-header"><h3 class="card-title">Program</h3></div>
    <div class="card-body">
      <input type="hidden" id="cat-id">
      <label class="form-label">Ad</label><input class="form-control mb-2" id="cat-name" maxlength="96" required>
      <label class="form-label">Simge (tabler, isteğe bağlı)</label><input class="form-control mb-2" id="cat-icon" maxlength="48" placeholder="apps">
      <label class="form-label">Yöntem</label>
      <select class="form-select mb-2" id="cat-method"><option value="winget">winget (Windows paket yöneticisi)</option><option value="url">URL (MSI/EXE)</option></select>
      <div id="cat-winget"><label class="form-label">winget paket kimliği</label><input class="form-control mb-2" id="cat-wid" placeholder="RARLab.WinRAR"></div>
      <div id="cat-url" hidden>
        <label class="form-label">İndirme adresi (https)</label><input class="form-control mb-2" id="cat-urlv" placeholder="https://.../kurulum.msi">
        <label class="form-label">sha256 (isteğe bağlı)</label><input class="form-control mb-2 mono" id="cat-sha" maxlength="64">
        <label class="form-label">Sessiz kurulum bayrakları (.exe için)</label><input class="form-control mb-2" id="cat-silent" placeholder="/S veya /quiet">
      </div>
      <label class="form-check"><input type="checkbox" class="form-check-input" id="cat-self" checked><span class="form-check-label">Self-servis (kullanıcı kendi kurabilir)</span></label>
      <div class="alert alert-danger mt-2 mb-0" id="cat-err" hidden></div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn btn-primary">Kaydet</button>
    </div>
  </form>
</dialog>

<dialog id="cat-install-dialog" class="aci-dialog">
  <form id="cat-install-form" class="card">
    <div class="card-header"><h3 class="card-title">Cihazlara kur</h3></div>
    <div class="card-body">
      <p class="text-secondary" id="cat-install-info"></p>
      <label class="form-label">Hedef</label>
      <select class="form-select" id="cat-install-loc"><option value="">Tüm cihazlar</option></select>
      <p class="text-secondary small mb-0 mt-2" id="cat-install-count"></p>
      <div class="alert alert-danger mt-2 mb-0" id="cat-install-err" hidden></div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn btn-primary">Kur</button>
    </div>
  </form>
</dialog>
<?php require __DIR__ . '/../views/dialogs.php'; ?>
<?php page_footer();
