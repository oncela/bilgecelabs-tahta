<?php
// Self servis: cihazdaki BilgeceLabs Destek penceresinden gelen istekler
// (destek talebi, izinli program kurulumu) ve pencereye gönderilen durum.
declare(strict_types=1);

require_once __DIR__ . '/alerts.php';

/** Ajanın poll isteğindeki "requests" dizisini işler (en çok 10). */
function selfservice_handle(int $deviceId, array $requests, string $user): void {
    $user = mb_substr($user, 0, 128) ?: null;
    foreach (array_slice($requests, 0, 10) as $r) {
        if (!is_array($r)) continue;
        $type = (string)($r['type'] ?? '');
        if ($type === 'ticket') {
            selfservice_ticket($deviceId, $user, (string)($r['subject'] ?? ''), (string)($r['message'] ?? ''));
        } elseif ($type === 'install') {
            selfservice_install($deviceId, (int)($r['catalog_id'] ?? 0));
        }
    }
}

function selfservice_ticket(int $deviceId, ?string $user, string $subject, string $message): void {
    $subject = trim(mb_substr($subject, 0, 160));
    $message = trim(mb_substr($message, 0, 4000));
    if ($subject === '' && $message === '') return;
    if ($subject === '') $subject = mb_substr($message, 0, 80);
    db()->prepare('INSERT INTO tickets (device_id, user_name, subject, message) VALUES (?, ?, ?, ?)')
        ->execute([$deviceId, $user, $subject, $message]);
    $id = (int)db()->lastInsertId();

    $st = db()->prepare('SELECT id, name, hostname FROM devices WHERE id = ?');
    $st->execute([$deviceId]);
    $label = device_label($st->fetch() ?: ['id' => $deviceId, 'name' => null, 'hostname' => null]);
    notify_channels('🛟 ' . ACI_PRODUCT . " destek talebi #$id",
        "$label" . ($user ? " ($user)" : '') . ": $subject" . ($message !== '' ? "\n$message" : ''),
        ['type' => 'ticket', 'ticket_id' => $id]);
}

/** Yalnız self servise açık katalog programı kurulur; aynı kurulum bekliyorsa tekrarlanmaz. */
function selfservice_install(int $deviceId, int $catalogId): void {
    $st = db()->prepare('SELECT * FROM software_catalog WHERE id = ? AND self_service = 1');
    $st->execute([$catalogId]);
    $app = $st->fetch();
    if (!$app) return;
    $args = json_encode([
        'method' => $app['method'], 'id' => $app['winget_id'], 'url' => $app['url'],
        'sha256' => $app['sha256'], 'silent' => $app['silent'], 'name' => $app['name'],
    ], JSON_UNESCAPED_UNICODE);
    $dup = db()->prepare("SELECT 1 FROM commands WHERE device_id = ? AND type = 'install_software'
                          AND args = ? AND status IN ('pending','sent')");
    $dup->execute([$deviceId, $args]);
    if ($dup->fetchColumn()) return;
    db()->prepare("INSERT INTO commands (device_id, batch_id, type, args) VALUES (?, ?, 'install_software', ?)")
        ->execute([$deviceId, new_batch_id(), $args]);
    audit('selfservice_install', $app['name'] . " -> cihaz #$deviceId");
}

/** Destek penceresinin göstereceği durum: izinli programlar, talepler, son kurulumlar. */
function selfservice_state(int $deviceId): array {
    $catalog = db()->query('SELECT id, name FROM software_catalog WHERE self_service = 1 ORDER BY sort, name')->fetchAll();
    $st = db()->prepare('SELECT id, subject, status, reply, created_at FROM tickets WHERE device_id = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$deviceId]);
    $tickets = $st->fetchAll();
    $st = db()->prepare("SELECT args, status, created_at FROM commands WHERE device_id = ? AND type = 'install_software'
                         ORDER BY id DESC LIMIT 5");
    $st->execute([$deviceId]);
    $installs = array_map(fn($c) => [
        'name' => json_decode($c['args'], true)['name'] ?? '?',
        'status' => $c['status'],
        'created_at' => $c['created_at'],
    ], $st->fetchAll());
    return [
        'catalog' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $catalog),
        'tickets' => array_map(fn($t) => ['id' => (int)$t['id']] + $t, $tickets),
        'installs' => $installs,
    ];
}
