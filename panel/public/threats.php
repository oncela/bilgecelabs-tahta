<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Tehditler', 'threats.php');
?>
<div data-page="threats">
  <div class="alert-outbreak" id="th-outbreak" hidden role="alert">
    <i class="ti ti-virus"></i>
    <div><strong>Salgın şüphesi:</strong> <span id="th-outbreak-text"></span>
      <div class="small">Aynı tehdit son 7 günde birden çok cihazda görüldü. Ortak kaynağı (USB bellek, paylaşılan dosya, indirme) arayın.</div></div>
  </div>

  <div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
      <div class="eyebrow">Etkin tehdit olan cihaz</div><div class="stat-num" id="th-active">—</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
      <div class="eyebrow">Tespit (<span id="th-days-label">30 gün</span>)</div><div class="stat-num" id="th-count">—</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
      <div class="eyebrow">Etkilenen cihaz</div><div class="stat-num" id="th-affected">—</div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
      <div class="eyebrow">PUA koruması açık</div><div class="stat-num" id="th-pua">—</div></div></div></div>
  </div>

  <div class="card mb-3 pua-card">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <span class="pua-icon"><i class="ti ti-shield-lock"></i></span>
      <div>
        <h3 class="card-title mb-1">PUA koruması (istenmeyen yazılım)</h3>
        <div class="text-secondary small">Crack/keygen araçlarını, reklam yazılımlarını ve izinsiz paket yükleyicileri engeller —
          okul bilgisayarlarında en sık bulaşma kaynağı. Önce <b>izleme modu</b> ile birkaç gün ne engelleneceğini görebilirsiniz.</div>
        <div class="small mt-2" id="th-pua-detail"></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn" id="pua-audit"><i class="ti ti-eye me-1"></i>İzleme modunda aç</button>
        <button class="btn btn-primary" id="pua-on"><i class="ti ti-shield-check me-1"></i>Tüm cihazlarda aç</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header flex-wrap gap-2">
      <h3 class="card-title">Tespitler</h3>
      <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
        <label class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="th-only-active"><span class="form-check-label">Yalnız etkin</span></label>
        <select class="form-select form-select-sm w-auto" id="th-days" aria-label="Süre">
          <option value="7">Son 7 gün</option><option value="30" selected>Son 30 gün</option><option value="90">Son 90 gün</option><option value="365">Son 1 yıl</option>
        </select>
        <input type="search" class="form-control form-control-sm w-auto" id="th-search" placeholder="Tehdit, cihaz veya dosya ara" aria-label="Ara">
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead><tr><th>Tarih</th><th>Cihaz</th><th>Tehdit</th><th>Önem</th><th>Kaynak ve dosya</th><th>Durum</th><th></th></tr></thead>
        <tbody id="th-rows"><tr class="empty-row"><td colspan="7">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>
  <p class="text-secondary small mt-2">Tespitler cihazlardaki Microsoft Defender'dan 15 dakikada bir gelir. Dosyaların kendisi gönderilmez, yalnız adı ve yolu.</p>
</div>
<?php page_footer();
