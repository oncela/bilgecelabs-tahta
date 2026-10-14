<?php
// Tahta kaydı: ajan kayıt anahtarıyla gelir, kendine özel token alır.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST gerekli'], 405);

$in = json_in();
$key = (string)($in['enroll_key'] ?? '');
$expected = setting('enroll_key');
if ($expected === null || $key === '' || !hash_equals($expected, $key)) {
    usleep(500000); // tahmin denemelerini yavaşlat
    json_out(['error' => 'Kayıt anahtarı geçersiz'], 403);
}

$hostname = mb_substr((string)($in['hostname'] ?? ''), 0, 128);
$mac = strtolower(mb_substr((string)($in['mac'] ?? ''), 0, 32));
$os = mb_substr((string)($in['os'] ?? ''), 0, 160);
$ver = mb_substr((string)($in['version'] ?? ''), 0, 32);
$allowed = ['board', 'classroom', 'office', 'desk', 'other'];
$devType = in_array($in['dev_type'] ?? '', $allowed, true) ? $in['dev_type'] : 'board';
$room = trim(mb_substr((string)($in['room'] ?? ''), 0, 64)) ?: null;
$devName = trim(mb_substr((string)($in['dev_name'] ?? ''), 0, 128)) ?: null;

$token = bin2hex(random_bytes(32));
$hash = hash('sha256', $token);

// Aynı tahta yeniden kurulduysa (aynı MAC) eski kaydı koru, sadece token'ı yenile.
$id = null;
if ($mac !== '') {
    $st = db()->prepare('SELECT id FROM devices WHERE mac = ? ORDER BY id LIMIT 1');
    $st->execute([$mac]);
    $id = $st->fetchColumn() ?: null;
}
if ($id) {
    // Yeniden kurulum: kimlik korunur. Tür/konum/ad yalnız panelde boşsa güncellenir.
    db()->prepare("UPDATE devices SET token_hash = ?, hostname = ?, os = ?, agent_version = ?, ip = ?,
            dev_type = IF(dev_type = '' OR dev_type IS NULL, ?, dev_type),
            room = COALESCE(room, ?), name = COALESCE(name, ?), last_seen = NOW() WHERE id = ?")
        ->execute([$hash, $hostname, $os, $ver, client_ip(), $devType, $room, $devName, $id]);
} else {
    db()->prepare('INSERT INTO devices (token_hash, hostname, mac, os, agent_version, ip, dev_type, room, name, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$hash, $hostname, $mac, $os, $ver, client_ip(), $devType, $room, $devName]);
    $id = (int)db()->lastInsertId();
}
db()->prepare("INSERT INTO audit_log (action, detail, ip) VALUES ('device_enroll', ?, ?)")
    ->execute(["#$id $hostname $mac", client_ip()]);

json_out(['device_id' => (int)$id, 'token' => $token]);
