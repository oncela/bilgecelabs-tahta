<?php
// Genel bakış: okulun durumu tek ekranda, dikkat gerekenler önce.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/status.php';
require __DIR__ . '/../views/layout.php';
require_once __DIR__ . '/../src/stats.php';
require_once __DIR__ . '/../src/charts.php';
require_login();

$devices = db()->query('SELECT d.id, d.hostname, d.name, d.room, d.dev_type, d.last_seen, d.defender, d.mesh_state, d.disk_used, d.disk_free, d.mem_used,
        l.name AS location FROM devices d LEFT JOIN locations l ON l.id = d.location_id ORDER BY l.sort, l.name, d.room, d.hostname')->fetchAll();
$total = count($devices);
$online = 0;
$alerts = [];   // [seviye, simge, metin, bağlantı]
$meshReady = 0;
$rooms = [];
$wall = [];     // lokasyon => [[etiket, durum, bağlantı, ipucu], ...]
foreach ($devices as $d) {
    $label = device_label($d);
    $on = is_online($d['last_seen']);
    $tileState = $on ? 'on' : 'off';
    if ($on) $online++;
    $room = $d['room'] ?: 'Sınıfı atanmamış';
    $rooms[$room] ??= ['total' => 0, 'online' => 0];
    $rooms[$room]['total']++;
    if ($on) $rooms[$room]['online']++;

    $def = defender_summary($d['defender']);
    if ($on && $def['state'] === 'bad') $tileState = 'bad';
    elseif ($on && (int)$d['disk_used'] >= 90) $tileState = 'warn';
    $wall[$d['location'] ?: 'Lokasyonsuz'][] = [
        $d['room'] ?: $label, $tileState, 'devices.php?q=' . urlencode($d['hostname']),
        $label . ' · ' . ($on ? 'açık' : 'kapalı') . ($d['room'] ? ' · ' . $d['room'] : ''),
    ];
    if ($def['state'] === 'bad') $alerts[] = ['danger', 'shield-x', "$label: {$def['text']}", 'devices.php?q=' . urlencode($d['hostname'])];
    elseif ($def['state'] === 'warn') $alerts[] = ['warning', 'shield-exclamation', "$label: virüs {$def['text']}", 'devices.php?q=' . urlencode($d['hostname'])];

    $mesh = mesh_summary($d['mesh_state']);
    if ($mesh['state'] === 'ok') $meshReady++;
    elseif ($mesh['state'] === 'bad') $alerts[] = ['warning', 'plug-x', "$label: uzaktan erişim {$mesh['text']}", 'devices.php?q=' . urlencode($d['hostname'])];

    // Öngörücü uyarı: disk dolmak üzere
    if ($on && $d['disk_used'] !== null && (int)$d['disk_used'] >= 90) {
        $alerts[] = ['warning', 'database', "$label: disk %{$d['disk_used']} dolu" . ($d['disk_free'] !== null ? " ({$d['disk_free']} GB boş)" : ''), 'devices.php?q=' . urlencode($d['hostname'])];
    }
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

$stats = dashboard_stats();
$openTickets = (int)db()->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
$problems = count(array_filter($alerts, fn($a) => $a[0] !== 'secondary'));
page_header('Genel bakış', 'dashboard.php');
?>
<div data-page="dashboard">
  <section class="card wall-hero mb-4" aria-label="Tahtaların anlık durumu">
    <div class="wall-summary">
      <div class="eyebrow">Şu an · <?= h(date('H:i')) ?></div>
      <div class="wall-figure"><span class="wall-on"><?= $online ?></span><span class="wall-total">/<?= $total ?></span></div>
      <div class="wall-caption">cihaz açık</div>
      <ul class="wall-facts">
        <li class="<?= $problems ? 'is-bad' : 'is-ok' ?>"><i class="ti ti-<?= $problems ? 'alert-triangle' : 'circle-check' ?>"></i><?= $problems ? $problems . ' sorun dikkat bekliyor' : 'Sorun yok' ?></li>
        <li><i class="ti ti-plug-connected"></i><?= $meshReady ?> cihaz uzaktan erişime hazır</li>
        <li class="<?= $openTickets ? 'is-warn' : '' ?>"><i class="ti ti-lifebuoy"></i><a href="tickets.php"><?= $openTickets ?> açık destek talebi</a></li>
        <?php if ($newNet): ?><li class="is-warn"><i class="ti ti-wifi"></i><a href="network.php">Ağda <?= $newNet ?> yeni cihaz</a></li><?php endif; ?>
      </ul>
    </div>
    <div class="wall">
      <?php if (!$wall): ?>
        <div class="wall-empty">Henüz cihaz yok. <a href="settings.php">Kurulum dosyasını indirip</a> ilk tahtaya kurun.</div>
      <?php endif; ?>
      <?php foreach ($wall as $loc => $tiles): $on = count(array_filter($tiles, fn($t) => $t[1] !== 'off')); ?>
        <div class="wall-group">
          <div class="wall-group-name"><?= h((string)$loc) ?> <span><?= $on ?>/<?= count($tiles) ?></span></div>
          <div class="wall-tiles">
            <?php foreach ($tiles as $i => [$tl, $st, $href, $tip]): ?>
              <a class="tile is-<?= h($st) ?>" href="<?= h($href) ?>" title="<?= h($tip) ?>" style="--i: <?= (int)$i ?>">
                <span class="tile-screen"></span><span class="tile-label"><?= h($tl) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="wall-legend"><span class="lg on"></span>Açık <span class="lg warn"></span>Dikkat <span class="lg bad"></span>Sorun <span class="lg off"></span>Kapalı</div>
    </div>
  </section>

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
      <div class="card">
        <div class="card-header"><h3 class="card-title">Son işlemler</h3><a class="ms-auto" href="history.php">Geçmiş</a></div>
        <?php if (!$recent): ?>
          <div class="card-body text-secondary">Henüz işlem yok.</div>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($recent as $r): ?>
              <div class="list-group-item">
                <div class="d-flex justify-content-between gap-2">
                  <span class="fw-medium"><?= h(action_label($r['action'])) ?></span>
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
  <div class="row row-cards mt-1">
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title">Son 7 gün kullanım</h3></div>
        <div class="card-body"><?= svg_bars($stats['labels'], [['Toplam açık saat', $stats['hours'], '--aci-chart-1']], 'Son 7 gün toplam açık saat ve kullanılan tahta sayısı',
              array_map(fn($b) => $b ? $b . ' tahta' : '', $stats['boards'])) ?></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title">İşlemler (7 gün)</h3></div>
        <div class="card-body"><?= svg_bars($stats['labels'], [['Tamamlanan', $stats['commands_done'], '--aci-chart-1'], ['Tamamlanamayan', $stats['commands_failed'], '--aci-bad']], 'Son 7 gün işlemler', w: 340) ?></div>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
