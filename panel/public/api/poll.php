<?php
// Ajanın düzenli bağlantısı: durum bildirir, komut sonuçlarını iletir,
// bekleyen komutları alır.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/mesh.php';

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
if (isset($in['mesh_state'])) {
    $pdo->prepare('UPDATE devices SET mesh_state = ? WHERE id = ?')
        ->execute([mb_substr((string)$in['mesh_state'], 0, 160), $deviceId]);
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
    $resp['mesh'] = ['service' => 'AciUzak', 'url' => '/api/meshagent.php'];
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
