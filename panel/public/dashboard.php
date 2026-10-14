<?php
// Genel bakış: okulun durumu tek ekranda, dikkat gerekenler önce.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/status.php';
require __DIR__ . '/../views/layout.php';
require_login();

$devices = db()->query('SELECT id, hostname, name, room, last_seen, defender, mesh_state FROM devices')->fetchAll();
$total = count($devices);
$online = 0;
$alerts = [];   // [seviye, simge, metin, bağlantı]
$meshReady = 0;
$rooms = [];
foreach ($devices as $d) {
    $label = device_label($d);
    $on = is_online($d['last_seen']);
    if ($on) $online++;
    $room = $d['room'] ?: 'Sınıfı atanmamış';
    $rooms[$room] ??= ['total' => 0, 'online' => 0];
    $rooms[$room]['total']++;
    if ($on) $rooms[$room]['online']++;

    $def = defender_summary($d['defender']);
    if ($def['state'] === 'bad') $alerts[] = ['danger', 'shield-x', "$label: {$def['text']}", 'devices.php?q=' . urlencode($d['hostname'])];
    elseif ($def['state'] === 'warn') $alerts[] = ['warning', 'shield-exclamation', "$label: virüs {$def['text']}", 'devices.php?q=' . urlencode($d['hostname'])];

    $mesh = mesh_summary($d['mesh_state']);
    if ($mesh['state'] === 'ok') $meshReady++;
    elseif ($mesh['state'] === 'bad') $alerts[] = ['warning', 'plug-x', "$label: uzaktan erişim {$mesh['text']}", 'devices.php?q=' . urlencode($d['hostname'])];

    if (!$on && $d['last_seen'] && strtotime($d['last_seen']) < time() - 7 * 86400) {
        $alerts[] = ['secondary', 'power', "$label: 7 günden uzun süredir bağlanmadı", 'devices.php?q=' . urlencode($d['hostname'])];
    }
}
ksort($rooms, SORT_NATURAL);

$newNet = (int)db()->query('SELECT COUNT(*) FROM net_devices n WHERE n.known = 0
    AND n.first_seen > NOW() - INTERVAL 7 DAY
    AND NOT EXISTS (SELECT 1 FROM devices d WHERE d.mac = n.mac)')->fetchColumn();
if ($newNet) $alerts[] = ['warning', 'wifi', "Ağda tanınmayan $newNet yeni cihaz", 'network.php'];

$failed = (int)db()->query("SELECT COUNT(*) FROM commands WHERE status IN ('failed','expired')
    AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
if ($failed) $alerts[] = ['secondary', 'alert-circle', "Son 24 saatte $failed işlem tamamlanamadı", 'history.php'];

$order = ['danger' => 0, 'warning' => 1, 'secondary' => 2];
usort($alerts, fn($a, $b) => $order[$a[0]] <=> $order[$b[0]]);

$recent = db()->query('SELECT l.created_at, l.action, l.detail, a.username FROM audit_log l
    LEFT JOIN admins a ON a.id = l.admin_id
    WHERE l.action NOT IN (\'login\', \'logout\') ORDER BY l.id DESC LIMIT 8')->fetchAll();
$actionNames = [
    'command' => 'Komut', 'device_enroll' => 'Tahta kaydı', 'device_update' => 'Tahta düzenleme',
    'device_delete' => 'Tahta silme', 'netscan' => 'Ağ taraması', 'login_failed' => 'Hatalı giriş',
    'remote_desktop' => 'Ekran açıldı', 'remote_files' => 'Dosyalar açıldı', 'remote_terminal' => 'Komut satırı açıldı',
    'download' => 'Kurulum indirme', 'enroll_key' => 'Kayıt anahtarı', 'password' => 'Şifre değişikliği',
];

page_header('Genel bakış', 'dashboard.php');
?>
<div data-page="dashboard">
  <div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3">
      <div class="card card-sm"><div class="card-body">
        <div class="text-secondary">Açık tahta</div>
        <div class="h1 mb-0"><?= $online ?> <span class="text-secondary fs-3">/ <?= $total ?></span></div>
      </div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <?php $bad = count(array_filter($alerts, fn($a) => $a[0] === 'danger')); ?>
      <div class="card card-sm"><div class="card-body">
        <div class="text-secondary">Virüs uyarısı</div>
        <div class="h1 mb-0 <?= $bad ? 'text-danger' : '' ?>"><?= $bad ?></div>
      </div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card card-sm"><div class="card-body">
        <div class="text-secondary">Uzaktan erişime hazır</div>
        <div class="h1 mb-0"><?= $meshReady ?> <span class="text-secondary fs-3">/ <?= $total ?></span></div>
      </div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card card-sm"><div class="card-body">
        <div class="text-secondary">Yeni ağ cihazı (7 gün)</div>
        <div class="h1 mb-0 <?= $newNet ? 'text-warning' : '' ?>"><?= $newNet ?></div>
      </div></div>
    </div>
  </div>

  <div class="row row-cards">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Dikkat gerekenler</h3></div>
        <?php if (!$alerts): ?>
          <div class="card-body text-secondary"><i class="ti ti-circle-check text-success me-1"></i>Şu an dikkat gerektiren bir durum yok.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach (array_slice($alerts, 0, 12) as [$level, $icon, $text, $href]): ?>
              <a href="<?= h($href) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                <i class="ti ti-<?= h($icon) ?> text-<?= h($level) ?>"></i>
                <span class="flex-fill"><?= h($text) ?></span>
                <i class="ti ti-chevron-right text-secondary"></i>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Sınıflar</h3><a class="ms-auto" href="rooms.php">Tümü</a></div>
        <?php if (!$rooms): ?>
          <div class="card-body text-secondary">Henüz tahta yok. <a href="settings.php">Kurulum dosyasını indirin</a>.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach (array_slice($rooms, 0, 10, true) as $room => $c): ?>
              <a href="rooms.php#<?= h(rawurlencode((string)$room)) ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                <span class="flex-fill"><?= h((string)$room) ?></span>
                <span class="badge <?= $c['online'] ? 'bg-success-lt' : 'bg-secondary-lt' ?>"><?= $c['online'] ?> / <?= $c['total'] ?> açık</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Son işlemler</h3><a class="ms-auto" href="history.php">Geçmiş</a></div>
        <?php if (!$recent): ?>
          <div class="card-body text-secondary">Henüz işlem yok.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($recent as $r): ?>
              <div class="list-group-item">
                <div class="d-flex justify-content-between gap-2">
                  <span class="fw-medium"><?= h($actionNames[$r['action']] ?? $r['action']) ?></span>
                  <span class="text-secondary small text-nowrap"><?= h(date('d.m H:i', strtotime($r['created_at']))) ?></span>
                </div>
                <div class="text-secondary small text-truncate"><?= h(trim(($r['username'] ? $r['username'] . ' · ' : '') . $r['detail'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
