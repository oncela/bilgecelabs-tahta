<?php
// Tahtanın ekranı / dosyaları / komut satırı, panelin içinde.
// MeshCentral kendi (temalı) arayüzüyle çalışır: Bağlan düğmesine basınca
// ekran açılır, klavye ve fare kontrolü doğrudan çalışır.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/mesh.php';
require_once __DIR__ . '/../src/status.php';
require __DIR__ . '/../views/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$view = isset(MESH_VIEWS[$_GET['view'] ?? '']) ? $_GET['view'] : 'desktop';
$st = db()->prepare('SELECT * FROM devices WHERE id = ?');
$st->execute([$id]);
$d = $st->fetch();
$st = db()->prepare('SELECT mesh_user FROM admins WHERE id = ?');
$st->execute([$_SESSION['admin_id']]);
$meshUser = (string)$st->fetchColumn();

$error = '';
if (!$d) $error = 'Tahta bulunamadı.';
elseif (!mesh_enabled()) $error = 'Uzaktan erişim sunucusu ayarlanmamış.';
elseif ($meshUser === '') $error = 'Hesabınıza uzaktan erişim yetkisi verilmemiş.';
elseif ($d['mesh_state'] !== 'running') $error = 'Bu cihazda uzaktan erişim hazır değil (' . mesh_summary($d['mesh_state'])['text'] . ').';
elseif (!is_online($d['last_seen'])) $error = 'Cihaz şu an kapalı veya internete bağlı değil.';

$url = '';
if (!$error) {
    $url = mesh_device_url($meshUser, $d, $view);
    audit('remote_' . $view, '#' . $d['id'] . ' ' . device_label($d));
}
$icons = ['desktop' => 'device-desktop', 'terminal' => 'terminal-2', 'files' => 'folder'];
page_header(($d ? device_label($d) : 'Cihaz') . ' · ' . MESH_VIEWS[$view][1], 'devices.php');
?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <a class="btn" href="devices.php"><i class="ti ti-arrow-left me-1"></i>Cihazlar</a>
<?php else: ?>
<div class="d-grid gap-2">
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <ul class="nav nav-pills">
      <?php foreach (MESH_VIEWS as $k => [, $label]): ?>
        <li class="nav-item"><a href="remote.php?id=<?= $id ?>&amp;view=<?= $k ?>" class="nav-link <?= $k === $view ? 'active' : '' ?>"><i class="ti ti-<?= $icons[$k] ?> me-1"></i><?= h($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <a class="btn btn-ghost-secondary ms-auto" target="_blank" rel="noopener" href="<?= h($url) ?>"><i class="ti ti-external-link me-1"></i>Yeni sekmede aç</a>
    <a class="btn btn-ghost-secondary" href="devices.php"><i class="ti ti-arrow-left me-1"></i>Cihazlar</a>
  </div>
  <div class="card" style="overflow:hidden">
    <iframe src="<?= h($url) ?>" title="Uzaktan erişim" allow="clipboard-read; clipboard-write; fullscreen"
      style="width:100%;height:calc(100vh - 210px);min-height:480px;border:0;background:#0b1220;display:block"></iframe>
  </div>
  <p class="text-secondary small mb-0">Ekranı açmak için yukarıdaki yeşil <b>Bağlan</b> düğmesine basın. Bağlantı süresince cihazda "Uzaktan bağlantı" bildirimi görünür ve işlem geçmişe kaydedilir. Ekran açılmazsa <b>Yeni sekmede aç</b>'ı kullanın.</p>
</div>
<?php endif; ?>
<?php page_footer();
