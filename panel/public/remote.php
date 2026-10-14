<?php
// Tahtanın ekranı / dosyaları / komut satırı, panelin içinde.
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
elseif ($d['mesh_state'] !== 'running') $error = 'Bu tahtada uzaktan erişim hazır değil (' . mesh_summary($d['mesh_state'])['text'] . '). Tahta açıldığında ajan otomatik kurar.';
elseif (!is_online($d['last_seen'])) $error = 'Tahta şu an kapalı veya internete bağlı değil (son bağlantı: ' . date('d.m.Y H:i', strtotime((string)$d['last_seen'])) . ').';

$url = '';
if (!$error) {
    $url = mesh_device_url($meshUser, $d, $view);
    audit('remote_' . $view, '#' . $d['id'] . ' ' . device_label($d));
}
$meshOrigin = '';
if ($url !== '') {
    $u = parse_url(cfg('ACI_MESH_URL'));
    $meshOrigin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
}
$icons = ['desktop' => 'device-desktop', 'terminal' => 'terminal-2', 'files' => 'folder'];
page_header(($d ? device_label($d) : 'Tahta') . ' · ' . MESH_VIEWS[$view][1], 'devices.php');
?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <a class="btn" href="devices.php"><i class="ti ti-arrow-left me-1"></i>Tahtalar</a>
<?php else: ?>
<div data-page="remote" data-mesh-origin="<?= h($meshOrigin) ?>" data-view="<?= h($view) ?>">
  <div class="card remote-card" id="remote-card">
    <div class="remote-bar">
      <div class="d-flex align-items-center gap-2 me-2">
        <span class="remote-dot" id="remote-dot"></span>
        <div class="lh-sm">
          <div class="fw-medium"><?= h(device_label($d)) ?></div>
          <div class="small text-secondary"><span id="remote-state">Bağlanıyor…</span> <span id="remote-timer"></span></div>
        </div>
      </div>
      <div class="btn-group" role="group" aria-label="Görünüm">
        <?php foreach (MESH_VIEWS as $k => [, $label]): ?>
          <a href="remote.php?id=<?= $id ?>&amp;view=<?= $k ?>" class="btn btn-sm <?= $k === $view ? 'btn-primary' : '' ?>"><i class="ti ti-<?= $icons[$k] ?> me-1"></i><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="d-flex flex-wrap gap-1 ms-auto">
        <?php if ($view === 'desktop'): ?>
          <button type="button" class="btn btn-sm" data-remote="control" id="remote-control" title="Fare ve klavye kontrolünü aç/kapat"><i class="ti ti-hand-click me-1"></i><span>Kontrol açık</span></button>
          <button type="button" class="btn btn-sm" data-remote="cad" title="Ctrl+Alt+Del gönder"><i class="ti ti-keyboard me-1"></i>Ctrl+Alt+Del</button>
          <button type="button" class="btn btn-sm" data-remote="screenshot" title="Ekran görüntüsünü kaydet"><i class="ti ti-camera me-1"></i>Görüntü</button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm" data-remote="reconnect" title="Yeniden bağlan"><i class="ti ti-refresh"></i></button>
        <button type="button" class="btn btn-sm" data-remote="disconnect" title="Bağlantıyı kes"><i class="ti ti-plug-x"></i></button>
        <button type="button" class="btn btn-sm" data-remote="fullscreen" title="Tam ekran"><i class="ti ti-maximize"></i></button>
        <a class="btn btn-sm btn-ghost-secondary" href="devices.php" title="Tahtalara dön"><i class="ti ti-x"></i></a>
      </div>
    </div>
    <div class="remote-stage">
      <iframe id="remote-frame" class="remote-frame" src="<?= h($url) ?>" title="Tahta uzaktan erişim" allow="clipboard-read; clipboard-write; fullscreen"></iframe>
    </div>
  </div>
  <p class="text-secondary small mt-2 mb-0" id="remote-note">Bağlantı süresince tahtanın ekranında "Uzaktan bağlantı" çubuğu görünür ve bu işlem geçmişe kaydedilir.</p>
</div>
<?php endif; ?>
<?php page_footer();
