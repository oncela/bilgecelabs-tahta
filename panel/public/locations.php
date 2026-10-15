<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Lokasyonlar', 'locations.php');
?>
<div data-page="locations">
  <div class="row row-cards">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Yeni lokasyon / şube</h3></div>
        <div class="card-body">
          <form id="loc-form" class="d-flex gap-2">
            <input class="form-control" id="loc-name" maxlength="96" placeholder="ör. Side Ana Bina, İstanbul Şube" required>
            <button class="btn btn-primary" type="submit">Ekle</button>
          </form>
          <p class="text-secondary small mt-2 mb-0">Lokasyonlar şube/bina, departmanlar (cihazın "konum" alanı) ise o lokasyondaki bölümlerdir (ör. 7-B, Muhasebe, Danışma).</p>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Lokasyonlar</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead><tr><th>Ad</th><th>Cihaz</th><th></th></tr></thead>
            <tbody id="loc-rows"><tr class="empty-row"><td colspan="3">Yükleniyor…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
