<?php
// Ajanın düzenli bağlantısı: durum bildirir, komut sonuçlarını iletir,
// bekleyen komutları alır.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST gerekli'], 405);

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer ([0-9a-f]{64})$/', $auth, $m)) json_out(['error' => 'token yok'], 401);
$st = db()->prepare('SELECT id FROM devices WHERE token_hash = ?');
$st->execute([hash('sha256', $m[1])]);
$deviceId = (int)$st->fetchColumn();
if (!$deviceId) json_out(['error' => 'token geçersiz'], 401);

$in = json_in();
$pdo = db();

// 1) Durum
$pdo->prepare('UPDATE devices SET hostname = ?, logged_user = ?, uptime_s = ?, agent_version = ?, os = ?, ip = ?, last_seen = NOW() WHERE id = ?')
    ->execute([
        mb_substr((string)($in['hostname'] ?? ''), 0, 128),
        mb_substr((string)($in['user'] ?? ''), 0, 128),
        max(0, (int)($in['uptime_s'] ?? 0)),
        mb_substr((string)($in['version'] ?? ''), 0, 32),
        mb_substr((string)($in['os'] ?? ''), 0, 160),
        client_ip(),
        $deviceId,
    ]);
if (isset($in['defender']) && is_array($in['defender'])) {
    $pdo->prepare('UPDATE devices SET defender = ?, defender_at = NOW() WHERE id = ?')
        ->execute([json_encode($in['defender'], JSON_UNESCAPED_UNICODE), $deviceId]);
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

json_out(['commands' => $cmds, 'interval' => ACI_POLL_INTERVAL]);

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
