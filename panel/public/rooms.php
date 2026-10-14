<?php
// Sınıflar: her sınıf bir kart; tek tıkla ekran, mesaj, kapatma.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();
page_header('Sınıflar', 'rooms.php');
?>
<div data-page="rooms">
  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <span class="text-secondary" id="room-counts"></span>
    <a class="btn btn-ghost-secondary ms-auto" href="devices.php"><i class="ti ti-list me-1"></i>Liste görünümü</a>
  </div>
  <div class="row row-cards" id="room-grid">
    <div class="col-12 text-secondary">Yükleniyor…</div>
  </div>
</div>
<?php require __DIR__ . '/../views/dialogs.php'; ?>
<?php page_footer();
