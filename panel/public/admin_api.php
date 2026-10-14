<?php
// Panel ekranlarının kullandığı JSON uç noktaları (oturum + CSRF korumalı).
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/status.php';
require_once __DIR__ . '/../src/meshctl.php';
require_once __DIR__ . '/../src/stats.php';
require_login();

$action = (string)($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) csrf_check();
$in = $isPost ? json_in() : [];

switch ("$action:" . ($isPost ? 'POST' : 'GET')) {

case 'devices:GET':
    $rows = db()->query('SELECT d.id, d.hostname, d.name, d.room, d.mac, d.os, d.agent_version, d.ip,
            d.logged_user, d.uptime_s, d.defender, d.defender_at, d.last_seen, d.mesh_state,
            (SELECT COUNT(*) FROM commands c WHERE c.device_id = d.id AND c.status IN (\'pending\',\'sent\')) AS busy
        FROM devices d ORDER BY d.room IS NULL, d.room, COALESCE(d.name, d.hostname)')->fetchAll();
    $out = [];
    foreach ($rows as $d) {
        $out[] = [
            'id' => (int)$d['id'],
            'label' => device_label($d),
            'hostname' => $d['hostname'],
            'room' => $d['room'] ?? '',
            'ip' => $d['ip'],
            'user' => $d['logged_user'],
            'os' => $d['os'],
            'version' => $d['agent_version'],
            'uptime_s' => (int)$d['uptime_s'],
            'online' => is_online($d['last_seen']),
            'last_seen' => $d['last_seen'],
            'defender' => defender_summary($d['defender']),
            'busy' => (int)$d['busy'],
            'mesh' => mesh_summary($d['mesh_state']),
        ];
    }
    json_out(['devices' => $out, 'now' => date('c')]);

case 'send:POST':
    $types = command_types();
    $type = (string)($in['type'] ?? '');
    if (!isset($types[$type])) json_out(['error' => 'Bilinmeyen işlem.'], 400);
    $ids = array_values(array_unique(array_map('intval', (array)($in['device_ids'] ?? []))));
    if (!$ids) json_out(['error' => 'Önce en az bir tahta seçin.'], 400);
    $args = [];
    foreach ($types[$type][1] as $k) {
        if (isset($in['args'][$k]) && $in['args'][$k] !== '') $args[$k] = mb_substr((string)$in['args'][$k], 0, 20000);
    }
    if ($type === 'message' && ($args['text'] ?? '') === '') json_out(['error' => 'Mesaj metni boş olamaz.'], 400);
    if ($type === 'run_app' && trim($args['path'] ?? '') === '') json_out(['error' => 'Program veya adres boş olamaz.'], 400);
    if ($type === 'powershell' && trim($args['script'] ?? '') === '') json_out(['error' => 'Komut boş olamaz.'], 400);

    // Hazır betik: tahtaya PowerShell olarak gider, geçmişte kendi adıyla görünür
    $auditType = $type;
    if (($script = preset_script($type)) !== null) {
        $type = 'powershell';
        $args = ['script' => $script, 'timeout' => '120'];
    }

    $batch = new_batch_id();
    $ins = db()->prepare('INSERT INTO commands (device_id, batch_id, type, args, created_by)
                          SELECT id, ?, ?, ?, ? FROM devices WHERE id = ?');
    $n = 0;
    foreach ($ids as $id) {
        $ins->execute([$batch, $type, json_encode($args, JSON_UNESCAPED_UNICODE), $_SESSION['admin_id'], $id]);
        $n += $ins->rowCount();
    }
    audit('command', "$auditType → " . count($ids) . ' tahta' . ($auditType === 'powershell' ? ': ' . mb_substr($args['script'], 0, 500) : ''));
    json_out(['batch_id' => $batch, 'count' => $n]);

case 'batch:GET':
    $st = db()->prepare('SELECT c.id, c.device_id, c.status, c.output, c.type, d.name, d.hostname, d.id AS did
        FROM commands c JOIN devices d ON d.id = c.device_id WHERE c.batch_id = ? ORDER BY d.room, d.name, d.hostname');
    $st->execute([(string)($_GET['id'] ?? '')]);
    $rows = array_map(fn($r) => [
        'device' => device_label(['id' => $r['did'], 'name' => $r['name'], 'hostname' => $r['hostname']]),
        'status' => $r['status'],
        'output' => $r['output'] ?? '',
        'type' => $r['type'],
    ], $st->fetchAll());
    json_out(['results' => $rows]);

case 'device_commands:GET':
    $st = db()->prepare('SELECT c.id, c.type, c.args, c.status, c.output, c.created_at, c.done_at, a.username
        FROM commands c LEFT JOIN admins a ON a.id = c.created_by
        WHERE c.device_id = ? ORDER BY c.id DESC LIMIT 50');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    json_out(['commands' => $st->fetchAll()]);

case 'update_device:POST':
    $id = (int)($in['id'] ?? 0);
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 128));
    $room = trim(mb_substr((string)($in['room'] ?? ''), 0, 64));
    db()->prepare('UPDATE devices SET name = ?, room = ? WHERE id = ?')
        ->execute([$name ?: null, $room ?: null, $id]);
    audit('device_update', "#$id ad=$name sınıf=$room");
    json_out(['ok' => true]);

case 'delete_device:POST':
    $id = (int)($in['id'] ?? 0);
    db()->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
    audit('device_delete', "#$id");
    json_out(['ok' => true]);

case 'net:GET':
    $rows = db()->query('SELECT n.*, d.name AS scanner_name, d.hostname AS scanner_host,
            (SELECT COUNT(*) FROM devices t WHERE t.mac = n.mac) AS is_board
        FROM net_devices n LEFT JOIN devices d ON d.id = n.seen_by
        ORDER BY INET_ATON(n.ip)')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'mac' => $r['mac'],
            'ip' => $r['ip'],
            'hostname' => $r['hostname'],
            'label' => $r['label'] ?? '',
            'known' => (bool)$r['known'],
            'is_board' => (bool)$r['is_board'],
            'first_seen' => $r['first_seen'],
            'last_seen' => $r['last_seen'],
            'new' => !$r['known'] && !$r['is_board'] && strtotime($r['first_seen']) > time() - 7 * 86400,
        ];
    }
    $last = db()->query("SELECT MAX(done_at) FROM commands WHERE type = 'netscan' AND status = 'done'")->fetchColumn();
    json_out(['devices' => $out, 'last_scan' => $last]);

case 'net_update:POST':
    $id = (int)($in['id'] ?? 0);
    db()->prepare('UPDATE net_devices SET label = ?, known = ? WHERE id = ?')
        ->execute([trim(mb_substr((string)($in['label'] ?? ''), 0, 128)) ?: null, !empty($in['known']) ? 1 : 0, $id]);
    json_out(['ok' => true]);

case 'net_scan:POST':
    // Çevrimiçi tahtalardan en son bağlananı ağı tarar.
    $id = db()->query('SELECT id FROM devices WHERE last_seen >= NOW() - INTERVAL ' . ACI_ONLINE_SECONDS . ' SECOND
                       ORDER BY last_seen DESC LIMIT 1')->fetchColumn();
    if (!$id) json_out(['error' => 'Çevrimiçi tahta yok. Tarama için en az bir tahtanın açık olması gerekiyor.'], 409);
    $batch = new_batch_id();
    db()->prepare("INSERT INTO commands (device_id, batch_id, type, args, created_by) VALUES (?, ?, 'netscan', '{}', ?)")
        ->execute([$id, $batch, $_SESSION['admin_id']]);
    audit('netscan', "#$id");
    json_out(['batch_id' => $batch]);

case 'mesh_action:POST':
    // MeshCentral üzerinden: uzaktan açma (wake), uyku, kapatma, web adresi açma, bildirim.
    $action = (string)($in['action'] ?? '');
    $ids = array_values(array_unique(array_map('intval', (array)($in['device_ids'] ?? []))));
    if (!$ids) json_out(['error' => 'Önce en az bir tahta seçin.'], 400);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, mesh_node_id FROM devices WHERE id IN ($ph)");
    $st->execute($ids);
    $rows = $st->fetchAll();
    $hex = array_values(array_filter(array_column($rows, 'mesh_node_id')));
    $missing = count($ids) - count($hex);
    if (!$hex) json_out(['error' => 'Seçilen tahtalarda uzaktan erişim kimliği yok. Tahtanın en az bir kez açılıp ajanın uzaktan erişimi kurması gerekir.'], 409);
    $args = (array)($in['args'] ?? []);
    $r = match ($action) {
        'wake', 'sleep', 'off', 'reset' => mesh_power($action, $hex),
        'open_url' => mesh_open_url($hex, trim((string)($args['url'] ?? ''))),
        'toast' => mesh_toast($hex, mb_substr((string)($args['title'] ?? ''), 0, 100), mb_substr(trim((string)($args['text'] ?? '')), 0, 500)),
        default => null,
    };
    if ($r === null) json_out(['error' => 'Bilinmeyen işlem.'], 400);
    audit('mesh_' . $action, count($hex) . ' tahta' . ($action === 'open_url' ? ': ' . mb_substr((string)($args['url'] ?? ''), 0, 300) : '') . ($r['ok'] ? '' : ' (hata)'));
    json_out(['ok' => $r['ok'], 'output' => $r['out'], 'sent' => count($hex), 'missing' => $missing]);

case 'shared_list:GET':
    $rows = db()->query('SELECT s.id, s.orig_name, s.size, s.subdir, s.created_at, a.username
        FROM shared_files s LEFT JOIN admins a ON a.id = s.created_by ORDER BY s.id DESC')->fetchAll();
    json_out(['files' => $rows]);

case 'shared_upload:POST':
    // CSRF başlığı üstte kontrol edildi ($_POST['csrf'] formda gelir)
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_out(['error' => 'Dosya yüklenemedi (boyut sınırı ' . ini_get('upload_max_filesize') . ' olabilir).'], 400);
    }
    $orig = mb_substr((string)$_FILES['file']['name'], 0, 255);
    if ($orig === '' || preg_match('#[/\\]#', $orig)) json_out(['error' => 'Geçersiz dosya adı.'], 400);
    $subdir = preg_replace('#[^\p{L}\p{N} _-]#u', '', trim((string)($_POST['subdir'] ?? '')));
    $subdir = mb_substr((string)$subdir, 0, 64);
    $stored = bin2hex(random_bytes(16));
    $dir = shared_dir();
    @mkdir($dir, 0750, true);
    $dest = $dir . '/' . $stored;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) json_out(['error' => 'Dosya kaydedilemedi.'], 500);
    $sha = hash_file('sha256', $dest);
    db()->prepare('INSERT INTO shared_files (orig_name, stored_name, size, sha256, subdir, created_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$orig, $stored, filesize($dest), $sha, $subdir, $_SESSION['admin_id']]);
    audit('shared_upload', $orig . ' (' . round(filesize($dest) / 1048576, 1) . ' MB)');
    json_out(['ok' => true]);

case 'shared_send:POST':
    $fid = (int)($in['file_id'] ?? 0);
    $st = db()->prepare('SELECT orig_name, sha256, subdir FROM shared_files WHERE id = ?');
    $st->execute([$fid]);
    $file = $st->fetch();
    if (!$file) json_out(['error' => 'Dosya bulunamadı.'], 404);
    $ids = array_values(array_unique(array_map('intval', (array)($in['device_ids'] ?? []))));
    if (!$ids) json_out(['error' => 'Önce en az bir tahta seçin.'], 400);
    $args = json_encode([
        'path' => '/api/shared.php?id=' . $fid,
        'name' => $file['orig_name'],
        'sha256' => $file['sha256'],
        'dir' => $file['subdir'],
    ], JSON_UNESCAPED_UNICODE);
    $batch = new_batch_id();
    $ins = db()->prepare("INSERT INTO commands (device_id, batch_id, type, args, created_by)
                          SELECT id, ?, 'fetch_file', ?, ? FROM devices WHERE id = ?");
    $n = 0;
    foreach ($ids as $id) { $ins->execute([$batch, $args, $_SESSION['admin_id'], $id]); $n += $ins->rowCount(); }
    audit('shared_send', $file['orig_name'] . ' -> ' . count($ids) . ' tahta');
    json_out(['batch_id' => $batch, 'count' => $n]);

case 'shared_delete:POST':
    $fid = (int)($in['id'] ?? 0);
    $st = db()->prepare('SELECT stored_name, orig_name FROM shared_files WHERE id = ?');
    $st->execute([$fid]);
    if ($f = $st->fetch()) {
        @unlink(shared_dir() . '/' . $f['stored_name']);
        db()->prepare('DELETE FROM shared_files WHERE id = ?')->execute([$fid]);
        audit('shared_delete', $f['orig_name']);
    }
    json_out(['ok' => true]);

case 'stats:GET':
    json_out(dashboard_stats());

default:
    json_out(['error' => 'Geçersiz istek.'], 404);
}
