<?php
// Tahtanın ekranı / dosyaları / komut satırı, panelin içinde.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/mesh.php';
require __DIR__ . '/../src/status.php';
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
elseif ($d['mesh_state'] !== 'running') $error = 'Bu tahtada uzaktan erişim hazır değil (' . mesh_summary($d['mesh_state'])['text'] . '). Tahta açıldığında ajan otomatik kurar.';
elseif (!is_online($d['last_seen'])) $error = 'Tahta şu an kapalı veya internete bağlı değil (son bağlantı: ' . date('d.m.Y H:i', strtotime((string)$d['last_seen'])) . ').';

$url = '';
if (!$error) {
    $url = mesh_device_url($meshUser, $d, $view);
    audit('remote_' . $view, '#' . $d['id'] . ' ' . device_label($d));
}
page_header(($d ? device_label($d) : 'Tahta') . ' · ' . MESH_VIEWS[$view][1], 'devices.php');
?>
<div class="d-grid gap-3">
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <ul class="nav nav-pills">
      <?php foreach (MESH_VIEWS as $k => [, $label]): ?>
        <li class="nav-item"><a href="remote.php?id=<?= $id ?>&amp;view=<?= $k ?>" class="nav-link <?= $k === $view ? 'active' : '' ?>"><?= h($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <a class="btn btn-ghost-secondary ms-auto" href="devices.php"><i class="ti ti-arrow-left me-1"></i>Tahtalar</a>
  </div>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php else: ?>
    <p class="text-secondary small mb-0">Bağlandığınızda tahtanın ekranında “uzaktan izleniyor” bildirimi görünür ve bu işlem geçmişe kaydedilir.
      Ekran aşağıda açılmazsa <a href="<?= h($url) ?>" target="_blank" rel="noopener">yeni sekmede açın</a>.</p>
    <iframe class="remote-frame" src="<?= h($url) ?>" title="Tahta uzaktan erişim" allow="clipboard-read; clipboard-write; fullscreen"></iframe>
  <?php endif; ?>
</div>
<?php page_footer();
