<?php
// Ajanın düzenli bağlantısı: durum bildirir, komut sonuçlarını iletir,
// bekleyen komutları alır.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mesh.php';
require_once __DIR__ . '/../../src/alerts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST gerekli'], 405);

$deviceId = device_from_token();

$in = json_in();
$pdo = db();

// 1) Durum
// Boş gelen alanlar mevcut değeri silmesin.
$pdo->prepare("UPDATE devices SET hostname = COALESCE(NULLIF(?, ''), hostname), logged_user = ?, uptime_s = ?,
        agent_version = COALESCE(NULLIF(?, ''), agent_version), os = COALESCE(NULLIF(?, ''), os), ip = ?, last_seen = NOW() WHERE id = ?")
    ->execute([
        mb_substr((string)($in['hostname'] ?? ''), 0, 128),
        mb_substr((string)($in['user'] ?? ''), 0, 128),
        max(0, (int)($in['uptime_s'] ?? 0)),
        mb_substr((string)($in['version'] ?? ''), 0, 32),
        mb_substr((string)($in['os'] ?? ''), 0, 160),
        client_ip(),
        $deviceId,
    ]);
if (isset($in['mesh_node']) && preg_match('/^[0-9A-F]{96}$/', (string)$in['mesh_node'])) {
    $pdo->prepare('UPDATE devices SET mesh_node_id = ? WHERE id = ?')->execute([$in['mesh_node'], $deviceId]);
}
if (isset($in['metrics']) && is_array($in['metrics'])) {
    $m = $in['metrics'];
    $clampPct = fn($v) => max(0, min(100, (int)$v));
    $pdo->prepare('UPDATE devices SET cpu = ?, mem_used = ?, mem_mb = ?, disk_used = ?, disk_free = ?, disk_gb = ? WHERE id = ?')
        ->execute([
            $clampPct($m['cpu'] ?? 0), $clampPct($m['mem_used'] ?? 0), max(0, (int)($m['mem_mb'] ?? 0)),
            $clampPct($m['disk_used'] ?? 0), max(0, (int)($m['disk_free'] ?? 0)), max(0, (int)($m['disk_gb'] ?? 0)),
            $deviceId,
        ]);
}
if (isset($in['mesh_state'])) {
    $pdo->prepare('UPDATE devices SET mesh_state = ? WHERE id = ?')
        ->execute([mb_substr((string)$in['mesh_state'], 0, 160), $deviceId]);
}
if (isset($in['inventory']) && is_array($in['inventory']) && !isset($in['inventory']['error'])) {
    $inv = $in['inventory'];
    $cut = fn($v, $n) => $v === null ? null : mb_substr((string)$v, 0, $n);
    $pdo->prepare('INSERT INTO device_inventory (device_id, serial, manufacturer, model, cpu, ram_gb, os, software_count, data, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE serial=VALUES(serial), manufacturer=VALUES(manufacturer), model=VALUES(model),
                cpu=VALUES(cpu), ram_gb=VALUES(ram_gb), os=VALUES(os), software_count=VALUES(software_count),
                data=VALUES(data), updated_at=NOW()')
        ->execute([$deviceId, $cut($inv['serial'] ?? null, 96), $cut($inv['manufacturer'] ?? null, 96),
            $cut($inv['model'] ?? null, 128), $cut($inv['cpu'] ?? null, 160), (int)($inv['ram_gb'] ?? 0) ?: null,
            $cut($inv['os'] ?? null, 160), (int)($inv['software_count'] ?? 0) ?: null,
            mb_substr(json_encode($inv, JSON_UNESCAPED_UNICODE), 0, 16000000)]);
}
if (isset($in['defender']) && is_array($in['defender'])) {
    $pdo->prepare('UPDATE devices SET defender = ?, defender_at = NOW() WHERE id = ?')
        ->execute([json_encode($in['defender'], JSON_UNESCAPED_UNICODE), $deviceId]);
}

// Kullanım kaydı (saatte bir satır) ve eski kayıtların temizliği
$pdo->prepare("INSERT IGNORE INTO device_activity (device_id, hour) VALUES (?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'))")
    ->execute([$deviceId]);
if (random_int(1, 500) === 1) {
    $pdo->exec('DELETE FROM device_activity WHERE hour < NOW() - INTERVAL ' . ACI_ACTIVITY_DAYS . ' DAY');
}

// Uyarı değerlendirmesi: bu cihazın güncel durumunu kontrol et
try {
    $dev = $pdo->prepare('SELECT id, hostname, name, disk_used, disk_free, mem_used, defender, mesh_state, last_seen FROM devices WHERE id = ?');
    $dev->execute([$deviceId]);
    if ($row = $dev->fetch()) alert_eval_device($row);
    // Filo süpürmesi (çevrimdışı tespiti) ara sıra
    if (random_int(1, 50) === 1) alert_sweep();
} catch (Throwable $e) { /* uyarı motoru poll'u düşürmesin */ }

// 2) Komut sonuçları
$upd = $pdo->prepare("UPDATE commands SET status = ?, output = ?, done_at = NOW()
                      WHERE id = ? AND device_id = ? AND status = 'sent'");
$typeOf = $pdo->prepare('SELECT type FROM commands WHERE id = ? AND device_id = ?');
foreach (($in['results'] ?? []) as $r) {
    if (!is_array($r) || !isset($r['id'])) continue;
    $ok = !empty($r['ok']);
    $out = mb_substr((string)($r['output'] ?? ''), 0, 65536 + 100);
    $upd->execute([$ok ? 'done' : 'failed', $out, (int)$r['id'], $deviceId]);
    if ($ok && $upd->rowCount()) {
        $typeOf->execute([(int)$r['id'], $deviceId]);
        if ($typeOf->fetchColumn() === 'netscan') ingest_netscan($out, $deviceId);
    }
}

// 3) Süresi geçen komutları iptal et (kapalı tahta sabah açılınca eski "kapat" komutunu almasın)
$pdo->exec("UPDATE commands SET status = 'expired' WHERE status = 'pending'
            AND created_at < NOW() - INTERVAL " . ACI_COMMAND_TTL_MIN . " MINUTE");

// 4) Bekleyen komutlar
$pdo->beginTransaction();
$st = $pdo->prepare("SELECT id, type, args FROM commands WHERE device_id = ? AND status = 'pending'
                     ORDER BY id LIMIT 20 FOR UPDATE");
$st->execute([$deviceId]);
$cmds = [];
$ids = [];
foreach ($st as $row) {
    $args = json_decode($row['args'], true) ?: [];
    $cmds[] = ['id' => (int)$row['id'], 'type' => $row['type'], 'args' => array_map('strval', $args)];
    $ids[] = (int)$row['id'];
}
if ($ids) {
    $idList = implode(',', $ids);
    $pdo->exec("UPDATE commands SET status = 'sent', sent_at = NOW() WHERE id IN ($idList)");
}
$pdo->commit();

$resp = ['commands' => $cmds, 'interval' => ACI_POLL_INTERVAL];
if (mesh_enabled() && cfg('ACI_MESH_GROUP') !== '') {
    // Ajan bu servisi kontrol eder; yoksa aşağıdaki adresten indirip kurar.
    $resp['mesh'] = ['service' => 'BilgeceUzak', 'url' => '/api/meshagent.php'];
}
json_out($resp);

function ingest_netscan(string $json, int $deviceId): void {
    $data = json_decode($json, true);
    if (!is_array($data['devices'] ?? null)) return;
    $st = db()->prepare("INSERT INTO net_devices (mac, ip, hostname, seen_by) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ip = VALUES(ip),
            hostname = IF(VALUES(hostname) = '', hostname, VALUES(hostname)),
            last_seen = NOW(), seen_by = VALUES(seen_by)");
    foreach ($data['devices'] as $d) {
        $mac = strtolower((string)($d['mac'] ?? ''));
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) continue;
        $st->execute([$mac, mb_substr((string)($d['ip'] ?? ''), 0, 64),
            mb_substr((string)($d['name'] ?? ''), 0, 255), $deviceId]);
    }
}
