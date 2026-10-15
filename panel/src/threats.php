<?php
// Tehditler: Defender tespitlerini saklar, Türkçe etiketler, salgın tespiti.
declare(strict_types=1);

require_once __DIR__ . '/alerts.php';

/** Tehdit adının önekinden (Trojan:, PUA:, HackTool: ...) okunur tür. */
function threat_kind(?string $name): string {
    $p = strtolower((string)strtok((string)$name, ':'));
    return [
        'trojan' => 'Truva atı', 'trojandownloader' => 'Truva atı (indirici)', 'trojandropper' => 'Truva atı (bırakıcı)',
        'trojanspy' => 'Casus truva atı', 'backdoor' => 'Arka kapı', 'ransom' => 'Fidye yazılımı', 'worm' => 'Solucan',
        'virus' => 'Virüs', 'exploit' => 'İstismar', 'pwsx' => 'Parola hırsızı', 'pws' => 'Parola hırsızı',
        'hacktool' => 'Saldırı aracı / crack', 'pua' => 'İstenmeyen yazılım (PUA)', 'app' => 'İstenmeyen yazılım (PUA)',
        'adware' => 'Reklam yazılımı', 'browsermodifier' => 'Tarayıcı değiştirici', 'softwarebundler' => 'Paket yükleyici',
        'remoteaccess' => 'Uzaktan erişim aracı', 'monitoringtool' => 'İzleme aracı', 'behavior' => 'Şüpheli davranış',
        'misleading' => 'Yanıltıcı yazılım', 'dos' => 'DoS aracı', 'tool' => 'Araç',
    ][$p] ?? 'Zararlı yazılım';
}

/** ThreatStatusID → [etiket, durum: ok/warn/bad] */
function threat_status(?int $s, bool $active): array {
    if ($active) return ['Etkin', 'bad'];
    return [
        1 => ['Tespit edildi', 'warn'], 2 => ['Temizlendi', 'ok'], 3 => ['Karantinada', 'ok'], 4 => ['Silindi', 'ok'],
        5 => ['İzin verildi', 'warn'], 6 => ['Engellendi', 'ok'], 102 => ['Karantina başarısız', 'bad'],
        103 => ['Silme başarısız', 'bad'], 104 => ['İzin başarısız', 'warn'], 105 => ['Bırakıldı', 'warn'], 107 => ['Engelleme başarısız', 'bad'],
    ][(int)$s] ?? ['Bilinmiyor', 'warn'];
}

function threat_source(?int $s): string {
    return [1 => 'Kullanıcı taraması', 2 => 'Sistem', 3 => 'Gerçek zamanlı koruma', 4 => 'İndirme / e-posta eki',
        5 => 'Ağ', 7 => 'Açılış koruması', 10 => 'Betik (AMSI)', 11 => 'UAC'][(int)$s] ?? 'Diğer';
}

function threat_severity(?int $s): array {
    return [1 => ['Düşük', 'ok'], 2 => ['Orta', 'warn'], 4 => ['Yüksek', 'bad'], 5 => ['Ciddi', 'bad']][(int)$s] ?? ['—', 'warn'];
}

/** PUA ayarı: 0 kapalı, 1 açık, 2 izleme modu */
function pua_label(?int $v): array {
    return $v === null ? ['Bilgi yok', 'off'] : ([0 => ['Kapalı', 'bad'], 1 => ['Açık', 'ok'], 2 => ['İzleme modu', 'warn']][$v] ?? ['?', 'off']);
}

/** Ajanın gönderdiği tespitleri saklar (poll.php). */
function threats_store(int $deviceId, array $detections): void {
    $ins = db()->prepare('INSERT INTO device_threats (device_id, detection_id, threat_id, name, severity, category, status, active,
            source, resources, process, user_name, detected_at, changed_at, seen_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE status = VALUES(status), active = VALUES(active), changed_at = VALUES(changed_at),
            name = COALESCE(VALUES(name), name), seen_at = NOW()');
    $dt = fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $v)) ? str_replace('T', ' ', substr($v, 0, 19)) : null;
    $cut = fn($v, $n) => $v === null || $v === '' ? null : mb_substr((string)$v, 0, $n);
    foreach (array_slice($detections, 0, 50) as $d) {
        if (!is_array($d) || empty($d['id'])) continue;
        $res = array_slice(array_map(fn($r) => mb_substr((string)$r, 0, 400), (array)($d['resources'] ?? [])), 0, 3);
        $ins->execute([$deviceId, $cut($d['id'], 64), $cut($d['threat_id'] ?? null, 32), $cut($d['name'] ?? null, 255),
            (int)($d['severity'] ?? 0) ?: null, (int)($d['category'] ?? 0) ?: null, (int)($d['status'] ?? 0),
            !empty($d['active']) ? 1 : 0, (int)($d['source'] ?? 0) ?: null, json_encode($res, JSON_UNESCAPED_UNICODE),
            $cut($d['process'] ?? null, 255), $cut($d['user'] ?? null, 128), $dt($d['detected'] ?? null), $dt($d['changed'] ?? null)]);
    }
    // Defender listesinde artık etkin olmayan tehditler bu cihazda etkin sayılmasın
    $activeIds = array_values(array_filter(array_map(fn($d) => !empty($d['active']) ? (string)($d['threat_id'] ?? '') : '', $detections)));
    if ($activeIds) {
        $ph = implode(',', array_fill(0, count($activeIds), '?'));
        db()->prepare("UPDATE device_threats SET active = 0 WHERE device_id = ? AND active = 1 AND threat_id NOT IN ($ph)")
            ->execute(array_merge([$deviceId], $activeIds));
    } else {
        db()->prepare('UPDATE device_threats SET active = 0 WHERE device_id = ? AND active = 1')->execute([$deviceId]);
    }
}

/** Son 7 günde aynı tehdidin 2+ cihazda görülmesi: [[ad, cihaz sayısı], ...] */
function threats_outbreaks(): array {
    return db()->query("SELECT name, COUNT(DISTINCT device_id) AS devices FROM device_threats
        WHERE name IS NOT NULL AND name <> '' AND detected_at > NOW() - INTERVAL 7 DAY
        GROUP BY name HAVING devices >= 2 ORDER BY devices DESC LIMIT 10")->fetchAll();
}

/** Salgın uyarısını açar/çözer (poll sonrası). */
function threats_eval_outbreak(): void {
    $ob = threats_outbreaks();
    if ($ob) {
        $txt = implode(', ', array_map(fn($o) => "{$o['name']} ({$o['devices']} cihaz)", array_slice($ob, 0, 3)));
        alert_open(null, 'outbreak', 'critical', "Salgın: aynı tehdit birden çok cihazda — $txt");
    } else {
        alert_resolve(null, 'outbreak');
    }
}
