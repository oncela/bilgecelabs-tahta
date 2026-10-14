<?php
// Tahtanın ekranı / dosyaları / komut satırı, panelin içinde.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/mesh.php';
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

$url = '';
if (!$error) {
    $url = mesh_device_url($meshUser, $d['hostname'], $view);
    audit('remote_' . $view, '#' . $d['id'] . ' ' . device_label($d));
}
page_header(($d ? device_label($d) : 'Tahta') . ' · ' . MESH_VIEWS[$view][1], 'devices.php');
?>
<div class="remote">
  <div class="head">
    <h1><?= h($d ? device_label($d) : 'Tahta') ?></h1>
    <nav class="tabs">
      <?php foreach (MESH_VIEWS as $k => [, $label]): ?>
        <a href="remote.php?id=<?= $id ?>&amp;view=<?= $k ?>" class="<?= $k === $view ? 'on' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
      <a href="devices.php">← Tahtalar</a>
    </nav>
  </div>
  <?php if ($error): ?>
    <p class="alert"><?= h($error) ?></p>
  <?php else: ?>
    <p class="muted small">Bağlandığınızda tahtanın ekranında “uzaktan izleniyor” bildirimi görünür ve bu işlem geçmişe kaydedilir.
      Ekran aşağıda açılmazsa <a href="<?= h($url) ?>" target="_blank" rel="noopener">yeni sekmede açın</a>.</p>
    <iframe class="remote-frame" src="<?= h($url) ?>" title="Tahta uzaktan erişim" allow="clipboard-read; clipboard-write; fullscreen"></iframe>
  <?php endif; ?>
</div>
<?php page_footer();
