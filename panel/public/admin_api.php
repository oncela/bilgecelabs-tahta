<?php
// Panel ekranlarının kullandığı JSON uç noktaları (oturum + CSRF korumalı).
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/status.php';
require_once __DIR__ . '/../src/meshctl.php';
require_once __DIR__ . '/../src/alerts.php';
require_once __DIR__ . '/../src/stats.php';
require_login();

$action = (string)($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) csrf_check();
$in = $isPost ? json_in() : [];

switch ("$action:" . ($isPost ? 'POST' : 'GET')) {

case 'devices:GET':
    $rows = db()->query('SELECT d.id, d.hostname, d.name, d.room, d.location_id, l.name AS location_name, d.dev_type, d.mac, d.os, d.agent_version, d.ip,
            d.logged_user, d.uptime_s, d.cpu, d.mem_used, d.mem_mb, d.disk_used, d.disk_free, d.disk_gb, d.fw_on, d.bitlocker, d.reboot_pending, d.uac, d.last_update, d.tamper, d.security_at, d.defender, d.defender_at, d.last_seen, d.mesh_state,
            (SELECT COUNT(*) FROM commands c WHERE c.device_id = d.id AND c.status IN (\'pending\',\'sent\')) AS busy
        FROM devices d LEFT JOIN locations l ON l.id = d.location_id
        ORDER BY l.sort, l.name, d.room IS NULL, d.room, COALESCE(d.name, d.hostname)')->fetchAll();
    $out = [];
    foreach ($rows as $d) {
        $out[] = [
            'id' => (int)$d['id'],
            'label' => device_label($d),
            'hostname' => $d['hostname'],
            'room' => $d['room'] ?? '',
            'location_id' => $d['location_id'] ? (int)$d['location_id'] : null,
            'location' => $d['location_name'] ?? '',
            'type' => $d['dev_type'] ?: 'board',
            'type_label' => device_types()[$d['dev_type'] ?: 'board'] ?? 'Diğer',
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
            'cpu' => $d['cpu'] === null ? null : (int)$d['cpu'],
            'mem_used' => $d['mem_used'] === null ? null : (int)$d['mem_used'],
            'mem_mb' => (int)$d['mem_mb'],
            'disk_used' => $d['disk_used'] === null ? null : (int)$d['disk_used'],
            'disk_free' => (int)$d['disk_free'],
            'disk_gb' => (int)$d['disk_gb'],
            'security' => $d['security_at'] === null ? null : [
                'fw_on' => $d['fw_on'] === null ? null : (bool)$d['fw_on'],
                'bitlocker' => $d['bitlocker'],
                'reboot_pending' => $d['reboot_pending'] === null ? null : (bool)$d['reboot_pending'],
                'uac' => $d['uac'] === null ? null : (bool)$d['uac'],
                'last_update' => $d['last_update'],
                'tamper' => $d['tamper'] === null ? null : (bool)$d['tamper'],
            ],
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
    if (($preset = preset_script($type)) !== null) {
        $type = 'powershell';
        $args = $preset;
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
    $type = in_array($in['type'] ?? '', array_keys(device_types()), true) ? $in['type'] : 'board';
    $loc = (int)($in['location_id'] ?? 0) ?: null;
    db()->prepare('UPDATE devices SET name = ?, room = ?, dev_type = ?, location_id = ? WHERE id = ?')
        ->execute([$name ?: null, $room ?: null, $type, $loc, $id]);
    audit('device_update', "#$id ad=$name sınıf=$room tür=$type");
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

case 'locations:GET':
    json_out(['locations' => db()->query('SELECT l.id, l.name,
        (SELECT COUNT(*) FROM devices d WHERE d.location_id = l.id) AS devices
        FROM locations l ORDER BY l.sort, l.name')->fetchAll()]);

case 'location_save:POST':
    $lid = (int)($in['id'] ?? 0);
    $lname = trim(mb_substr((string)($in['name'] ?? ''), 0, 96));
    if ($lname === '') json_out(['error' => 'Lokasyon adı boş olamaz.'], 400);
    if ($lid) {
        db()->prepare('UPDATE locations SET name = ? WHERE id = ?')->execute([$lname, $lid]);
    } else {
        db()->prepare('INSERT INTO locations (name) VALUES (?)')->execute([$lname]);
        $lid = (int)db()->lastInsertId();
    }
    audit('location_save', "#$lid $lname");
    json_out(['ok' => true, 'id' => $lid]);

case 'location_delete:POST':
    $lid = (int)($in['id'] ?? 0);
    // Cihazlar silinmez, lokasyonu boşa düşer
    db()->prepare('UPDATE devices SET location_id = NULL WHERE location_id = ?')->execute([$lid]);
    db()->prepare('DELETE FROM locations WHERE id = ?')->execute([$lid]);
    audit('location_delete', "#$lid");
    json_out(['ok' => true]);

case 'assign_location:POST':
    $loc = (int)($in['location_id'] ?? 0) ?: null;
    $ids = array_values(array_unique(array_map('intval', (array)($in['device_ids'] ?? []))));
    if (!$ids) json_out(['error' => 'Önce cihaz seçin.'], 400);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE devices SET location_id = ? WHERE id IN ($ph)")->execute(array_merge([$loc], $ids));
    audit('assign_location', ($loc ?? 'yok') . ' <- ' . count($ids) . ' cihaz');
    json_out(['ok' => true, 'count' => count($ids)]);

case 'alerts:GET':
    $open = db()->query("SELECT a.id, a.device_id, a.type, a.severity, a.message, a.created_at,
            d.hostname, d.name FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
            WHERE a.status = 'open' ORDER BY a.severity = 'critical' DESC, a.created_at DESC")->fetchAll();
    $resolved = db()->query("SELECT a.id, a.type, a.severity, a.message, a.created_at, a.resolved_at,
            d.hostname, d.name FROM alerts a LEFT JOIN devices d ON d.id = a.device_id
            WHERE a.status = 'resolved' ORDER BY a.resolved_at DESC LIMIT 50")->fetchAll();
    json_out(['open' => $open, 'resolved' => $resolved, 'open_count' => count($open)]);

case 'alerts_count:GET':
    json_out(['open' => (int)db()->query("SELECT COUNT(*) FROM alerts WHERE status='open'")->fetchColumn()]);

case 'alert_resolve:POST':
    $aid = (int)($in['id'] ?? 0);
    db()->prepare("UPDATE alerts SET status='resolved', resolved_at=NOW() WHERE id=? AND status='open'")->execute([$aid]);
    audit('alert_resolve', "#$aid");
    json_out(['ok' => true]);

case 'tickets:GET':
    $rows = db()->query("SELECT t.id, t.device_id, t.user_name, t.subject, t.message, t.status, t.reply,
            t.created_at, t.updated_at, d.hostname, d.name, d.room, l.name AS location
        FROM tickets t LEFT JOIN devices d ON d.id = t.device_id LEFT JOIN locations l ON l.id = d.location_id
        ORDER BY t.status = 'open' DESC, t.id DESC LIMIT 200")->fetchAll();
    json_out(['tickets' => $rows]);

case 'tickets_count:GET':
    json_out(['open' => (int)db()->query("SELECT COUNT(*) FROM tickets WHERE status='open'")->fetchColumn()]);

case 'ticket_update:POST':
    // Yanıt yaz ve/veya durumu değiştir (kullanıcı yanıtı destek penceresinde görür)
    $tid = (int)($in['id'] ?? 0);
    $status = in_array($in['status'] ?? '', ['open', 'closed'], true) ? $in['status'] : null;
    $reply = array_key_exists('reply', $in) ? trim(mb_substr((string)$in['reply'], 0, 4000)) : null;
    db()->prepare('UPDATE tickets SET status = COALESCE(?, status), reply = COALESCE(?, reply), updated_at = NOW() WHERE id = ?')
        ->execute([$status, $reply === '' ? null : $reply, $tid]);
    audit('ticket_update', "#$tid" . ($status ? " $status" : '') . ($reply ? ' yanıt' : ''));
    json_out(['ok' => true]);

case 'threats:GET':
    require_once __DIR__ . '/../src/threats.php';
    $days = (int)($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
    $rows = db()->prepare("SELECT t.id, t.device_id, t.name, t.severity, t.status, t.active, t.source, t.resources, t.process,
            t.user_name, t.detected_at, t.changed_at, d.hostname, d.name AS dev_name, d.room, l.name AS location
        FROM device_threats t JOIN devices d ON d.id = t.device_id LEFT JOIN locations l ON l.id = d.location_id
        WHERE t.active = 1 OR t.detected_at > NOW() - INTERVAL ? DAY
        ORDER BY t.active DESC, t.detected_at DESC LIMIT 500");
    $rows->execute([$days]);
    $list = array_map(function ($r) {
        [$st, $stState] = threat_status($r['status'] === null ? null : (int)$r['status'], (bool)$r['active']);
        [$sev, $sevState] = threat_severity($r['severity'] === null ? null : (int)$r['severity']);
        return [
            'id' => (int)$r['id'], 'device_id' => (int)$r['device_id'],
            'device' => $r['dev_name'] ?: $r['hostname'], 'hostname' => $r['hostname'], 'room' => $r['room'], 'location' => $r['location'],
            'name' => $r['name'] ?: 'Bilinmeyen tehdit', 'kind' => threat_kind($r['name']),
            'severity' => $sev, 'severity_state' => $sevState, 'status' => $st, 'status_state' => $stState, 'active' => (bool)$r['active'],
            'source' => threat_source($r['source'] === null ? null : (int)$r['source']),
            'resources' => json_decode((string)$r['resources'], true) ?: [], 'process' => $r['process'], 'user' => $r['user_name'],
            'detected_at' => $r['detected_at'],
        ];
    }, $rows->fetchAll());
    $pua = db()->query('SELECT pua, COUNT(*) AS n FROM devices GROUP BY pua')->fetchAll();
    $puaCounts = ['on' => 0, 'audit' => 0, 'off' => 0, 'unknown' => 0];
    foreach ($pua as $p) $puaCounts[[0 => 'off', 1 => 'on', 2 => 'audit'][$p['pua'] === null ? -1 : (int)$p['pua']] ?? 'unknown'] += (int)$p['n'];
    $affected = (int)db()->query("SELECT COUNT(DISTINCT device_id) FROM device_threats WHERE active = 1 OR detected_at > NOW() - INTERVAL $days DAY")->fetchColumn();
    // Defender yerine başka antivirüsle korunan cihazlar (tespitleri o ürünün konsolunda)
    $otherAv = [];
    foreach (db()->query('SELECT defender FROM devices WHERE defender IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $dj) {
        foreach ((array)(json_decode((string)$dj, true)['av'] ?? []) as $a) {
            if (is_array($a) && !empty($a['on'])) { $n = mb_substr((string)($a['name'] ?? ''), 0, 40); $otherAv[$n] = ($otherAv[$n] ?? 0) + 1; break; }
        }
    }
    json_out([
        'threats' => $list,
        'summary' => [
            'active' => count(array_unique(array_map(fn($t) => $t['device_id'], array_filter($list, fn($t) => $t['active'])))),
            'detections' => count($list), 'affected' => $affected, 'days' => $days,
        ],
        'outbreaks' => threats_outbreaks(),
        'pua' => $puaCounts,
        'other_av' => $otherAv,
        'device_ids' => array_map('intval', db()->query('SELECT id FROM devices')->fetchAll(PDO::FETCH_COLUMN)),
    ]);

case 'inventory:GET':
    $rows = db()->query("SELECT d.id, d.hostname, d.name, d.room, l.name AS location,
            i.serial, i.manufacturer, i.model, i.cpu, i.ram_gb, i.os, i.software_count, i.updated_at
        FROM devices d LEFT JOIN device_inventory i ON i.device_id = d.id
        LEFT JOIN locations l ON l.id = d.location_id
        ORDER BY l.sort, l.name, COALESCE(d.name, d.hostname)")->fetchAll();
    json_out(['devices' => $rows]);

case 'device_inventory:GET':
    $st = db()->prepare('SELECT data, updated_at FROM device_inventory WHERE device_id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $r = $st->fetch();
    json_out(['inventory' => $r ? json_decode($r['data'], true) : null, 'updated_at' => $r['updated_at'] ?? null]);

case 'inventory_csv:GET':
    $rows = db()->query("SELECT COALESCE(d.name, d.hostname) AS ad, l.name AS lokasyon, d.room AS departman,
            i.serial, i.manufacturer, i.model, i.cpu, i.ram_gb, i.os, i.software_count, i.updated_at
        FROM devices d LEFT JOIN device_inventory i ON i.device_id = d.id
        LEFT JOIN locations l ON l.id = d.location_id ORDER BY l.sort, l.name")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="envanter-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "ï»¿"); // UTF-8 BOM (Excel için)
    fputcsv($out, ['Ad', 'Lokasyon', 'Departman', 'Seri No', 'Üretici', 'Model', 'İşlemci', 'RAM (GB)', 'İşletim Sistemi', 'Yazılım Sayısı', 'Güncellenme']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;

case 'catalog:GET':
    json_out(['items' => db()->query('SELECT id, name, icon, method, winget_id, url, sha256, silent, self_service, sort
        FROM software_catalog ORDER BY sort, name')->fetchAll()]);

case 'catalog_save:POST':
    $cid = (int)($in['id'] ?? 0);
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 96));
    if ($name === '') json_out(['error' => 'Program adı boş olamaz.'], 400);
    $method = in_array($in['method'] ?? '', ['winget', 'url'], true) ? $in['method'] : 'winget';
    $wid = trim(mb_substr((string)($in['winget_id'] ?? ''), 0, 128)) ?: null;
    $url = trim(mb_substr((string)($in['url'] ?? ''), 0, 512)) ?: null;
    if ($method === 'winget' && !$wid) json_out(['error' => 'winget paket kimliği gerekli.'], 400);
    if ($method === 'url' && (!$url || !preg_match('#^https://#i', $url))) json_out(['error' => 'Geçerli https adresi gerekli.'], 400);
    $sha = preg_match('/^[0-9a-f]{64}$/i', (string)($in['sha256'] ?? '')) ? $in['sha256'] : null;
    $silent = trim(mb_substr((string)($in['silent'] ?? ''), 0, 96)) ?: null;
    $icon = preg_replace('/[^a-z0-9-]/', '', (string)($in['icon'] ?? '')) ?: null;
    $self = !empty($in['self_service']) ? 1 : 0;
    if ($cid) {
        db()->prepare('UPDATE software_catalog SET name=?, icon=?, method=?, winget_id=?, url=?, sha256=?, silent=?, self_service=? WHERE id=?')
            ->execute([$name, $icon, $method, $wid, $url, $sha, $silent, $self, $cid]);
    } else {
        db()->prepare('INSERT INTO software_catalog (name, icon, method, winget_id, url, sha256, silent, self_service) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $icon, $method, $wid, $url, $sha, $silent, $self]);
        $cid = (int)db()->lastInsertId();
    }
    audit('catalog_save', "#$cid $name");
    json_out(['ok' => true, 'id' => $cid]);

case 'catalog_delete:POST':
    db()->prepare('DELETE FROM software_catalog WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
    audit('catalog_delete', '#' . (int)($in['id'] ?? 0));
    json_out(['ok' => true]);

case 'catalog_install:POST':
    // Katalogdaki programı seçili cihazlara kurar (install_software komutu).
    $cid = (int)($in['catalog_id'] ?? 0);
    $st = db()->prepare('SELECT * FROM software_catalog WHERE id = ?');
    $st->execute([$cid]);
    $app = $st->fetch();
    if (!$app) json_out(['error' => 'Program bulunamadı.'], 404);
    $ids = array_values(array_unique(array_map('intval', (array)($in['device_ids'] ?? []))));
    if (!$ids) json_out(['error' => 'Önce cihaz seçin.'], 400);
    $args = json_encode([
        'method' => $app['method'], 'id' => $app['winget_id'], 'url' => $app['url'],
        'sha256' => $app['sha256'], 'silent' => $app['silent'], 'name' => $app['name'],
    ], JSON_UNESCAPED_UNICODE);
    $batch = new_batch_id();
    $insc = db()->prepare("INSERT INTO commands (device_id, batch_id, type, args, created_by)
                           SELECT id, ?, 'install_software', ?, ? FROM devices WHERE id = ?");
    foreach ($ids as $id) $insc->execute([$batch, $args, $_SESSION['admin_id'], $id]);
    audit('catalog_install', $app['name'] . ' -> ' . count($ids) . ' cihaz');
    json_out(['batch_id' => $batch, 'count' => count($ids)]);

case 'stats:GET':
    json_out(dashboard_stats());

default:
    json_out(['error' => 'Geçersiz istek.'], 404);
}
