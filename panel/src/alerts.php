<?php
// Alarm/uyarı motoru: eşik kurallarını değerlendirir, uyarı açar/çözer,
// yeni uyarıları bildirim kanallarına (e-posta, Telegram, webhook) gönderir.
declare(strict_types=1);

require_once __DIR__ . '/status.php';

/** Eşik ayarları (Ayarlar'dan değiştirilebilir, varsayılanlar burada). */
function alert_thresholds(): array {
    return [
        'disk_warn' => (int)(setting('alert_disk_warn') ?? 90),
        'disk_crit' => (int)(setting('alert_disk_crit') ?? 95),
        'mem_warn' => (int)(setting('alert_mem_warn') ?? 92),
        'offline_hours' => (int)(setting('alert_offline_hours') ?? 24),
    ];
}

/**
 * Bir uyarı açar (aynı cihaz+tür için açık uyarı varsa mesajını günceller, yoksa açar).
 * Yeni açıldıysa bildirim gönderilir.
 */
function alert_open(?int $deviceId, string $type, string $severity, string $message): void {
    $pdo = db();
    // Açık uyarı var mı?
    $st = $pdo->prepare('SELECT id FROM alerts WHERE device_id <=> ? AND type = ? AND status = "open"');
    $st->execute([$deviceId, $type]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE alerts SET severity = ?, message = ? WHERE id = ?')
            ->execute([$severity, mb_substr($message, 0, 255), $id]);
        return;
    }
    $pdo->prepare('INSERT INTO alerts (device_id, type, severity, message) VALUES (?, ?, ?, ?)')
        ->execute([$deviceId, $type, $severity, mb_substr($message, 0, 255)]);
    $newId = (int)$pdo->lastInsertId();
    alert_notify($newId, $severity, $message);
    $pdo->prepare('UPDATE alerts SET notified = 1 WHERE id = ?')->execute([$newId]);
}

/** Bir cihazın belirli türdeki açık uyarısını çözer. */
function alert_resolve(?int $deviceId, string $type): void {
    db()->prepare('UPDATE alerts SET status = "resolved", resolved_at = NOW()
                   WHERE device_id <=> ? AND type = ? AND status = "open"')
        ->execute([$deviceId, $type]);
}

/**
 * Tek bir cihazın anlık durumunu değerlendirir (poll sırasında çağrılır).
 * $d: devices satırı (disk_used, disk_free, mem_used, defender, mesh_state içermeli).
 */
function alert_eval_device(array $d): void {
    $t = alert_thresholds();
    $id = (int)$d['id'];
    $label = device_label($d);

    // Disk
    $disk = $d['disk_used'] === null ? null : (int)$d['disk_used'];
    if ($disk !== null && $disk >= $t['disk_warn']) {
        $sev = $disk >= $t['disk_crit'] ? 'critical' : 'warning';
        $free = $d['disk_free'] !== null ? " ({$d['disk_free']} GB boş)" : '';
        alert_open($id, 'disk', $sev, "$label: C: diski %$disk dolu$free");
    } else {
        alert_resolve($id, 'disk');
    }

    // Bellek
    $mem = $d['mem_used'] === null ? null : (int)$d['mem_used'];
    if ($mem !== null && $mem >= $t['mem_warn']) {
        alert_open($id, 'mem', 'warning', "$label: bellek %$mem dolu");
    } else {
        alert_resolve($id, 'mem');
    }

    // Güvenlik duvarı
    if (array_key_exists('fw_on', $d)) {
        if ($d['fw_on'] !== null && (int)$d['fw_on'] === 0) {
            alert_open($id, 'firewall', 'warning', "$label: güvenlik duvarı kapalı");
        } else {
            alert_resolve($id, 'firewall');
        }
    }
    // BitLocker (kapalıysa uyarı; eşik ayarıyla kapatılabilir)
    if (array_key_exists('bitlocker', $d) && (setting('alert_bitlocker') ?? '0') === '1') {
        if (($d['bitlocker'] ?? '') !== '' && stripos((string)$d['bitlocker'], 'On') === false) {
            alert_open($id, 'bitlocker', 'warning', "$label: disk şifreleme (BitLocker) kapalı");
        } else {
            alert_resolve($id, 'bitlocker');
        }
    }

    // Virüs koruması
    $def = defender_summary($d['defender'] ?? null);
    if ($def['state'] === 'bad') {
        alert_open($id, 'defender', 'critical', "$label: {$def['text']}");
    } else {
        alert_resolve($id, 'defender');
    }
}

/**
 * Tüm filoyu süpürür: çevrimdışı cihazlar için uyarı açar, çevrimiçi olanları çözer.
 * poll.php'de ara sıra (opportunistic) çağrılır.
 */
function alert_sweep(): void {
    $t = alert_thresholds();
    $hours = max(1, $t['offline_hours']);
    $rows = db()->query('SELECT id, hostname, name, last_seen FROM devices')->fetchAll();
    foreach ($rows as $d) {
        $id = (int)$d['id'];
        $label = device_label($d);
        $offline = $d['last_seen'] && strtotime($d['last_seen']) < time() - $hours * 3600;
        if ($offline) {
            $since = date('d.m.Y H:i', strtotime($d['last_seen']));
            alert_open($id, 'offline', 'warning', "$label: $hours saatten uzun süredir bağlanmadı (son: $since)");
        } else {
            alert_resolve($id, 'offline');
        }
    }
}

/** Yeni uyarıyı etkin bildirim kanallarına gönderir. */
function alert_notify(int $alertId, string $severity, string $message): void {
    $title = ($severity === 'critical' ? '🔴 ' : '🟠 ') . ACI_PRODUCT . ' uyarı';
    notify_channels($title, $message, ['severity' => $severity, 'alert_id' => $alertId]);
}

/** Bir bildirimi etkin kanallara (Telegram, webhook, e-posta) gönderir. */
function notify_channels(string $title, string $body, array $extra = []): void {
    // Telegram
    $tgToken = setting('notify_telegram_token');
    $tgChat = setting('notify_telegram_chat');
    if ($tgToken && $tgChat) {
        http_post_json("https://api.telegram.org/bot$tgToken/sendMessage", [
            'chat_id' => $tgChat,
            'text' => "$title\n$body",
        ]);
    }

    // Webhook
    $hook = setting('notify_webhook');
    if ($hook && filter_var($hook, FILTER_VALIDATE_URL)) {
        http_post_json($hook, $extra + [
            'product' => ACI_PRODUCT,
            'title' => $title,
            'message' => $body,
            'time' => date('c'),
        ]);
    }

    // E-posta
    $mail = setting('notify_email');
    if ($mail && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $headers = 'From: ' . (setting('notify_email_from') ?: 'bildirim@localhost') . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        @mail($mail, $title, $body, $headers);
    }
}

/** Kısa zaman aşımıyla JSON POST (bildirim panel akışını yavaşlatmasın). */
function http_post_json(string $url, array $data): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
