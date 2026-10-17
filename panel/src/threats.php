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

/** Okul paketindeki ASR kuralları (src/scripts/koruma.ps1 ile aynı) => Türkçe ad. */
function asr_rules(): array {
    return [
        'be9ba2d9-53ea-4cdc-84e5-9b1eeee46550' => "E-postadan gelen çalıştırılabilir içerik",
        'd4f940ab-401b-4efc-aadc-ad5f3c50688a' => "Office'in alt işlem başlatması",
        '3b576869-a4ec-4529-8536-b80a7769e899' => "Office'in çalıştırılabilir dosya üretmesi",
        '75668c1f-73b5-4cf0-bb93-3ecf5cb7cc84' => "Office'in kod enjekte etmesi",
        'd3e037e1-3eb8-44c8-a917-57927947596d' => 'Betiğin indirilen programı başlatması',
        '5beb7efe-fd9a-4556-801d-275e5ffc04cc' => 'Gizlenmiş betik',
        '92e97fa1-2edf-4476-bdd6-9dd0b4dddc7b' => 'Office makrosundan sistem çağrısı',
        'b2b3f03d-6a65-4f7b-a9c7-1c7ef74a9ba4' => "USB'den imzasız program",
        '9e6c4e1f-7d60-472f-ba1a-a39ef669e4b2' => 'Parola hırsızlığı (LSASS)',
        'e6db77e5-3df2-4cf1-b95a-636979351e5b' => 'WMI ile kalıcılık',
    ];
}

/**
 * Bir cihazın koruma ayarlarını ortak durumlara indirger: on / audit / off / unknown.
 * $pua: devices.pua, $h: devices.harden (JSON çözülmüş).
 */
function protection_states(?int $pua, ?array $h): array {
    $m = fn($v) => $v === null ? 'unknown' : ([0 => 'off', 1 => 'on', 2 => 'audit'][(int)$v] ?? 'off');
    $st = ['pua' => $m($pua)];
    if (!$h) return $st + ['asr' => 'unknown', 'net' => 'unknown', 'cfa' => 'unknown', 'autorun' => 'unknown', 'scan' => 'unknown'];
    $asr = (array)($h['asr'] ?? []);
    $modes = array_map(fn($id) => (int)($asr[$id] ?? 0), array_keys(asr_rules()));
    $st['asr'] = count(array_filter($modes, fn($x) => $x === 1)) === count($modes) ? 'on'
        : (count(array_filter($modes, fn($x) => $x === 2 || $x === 1)) === count($modes) ? 'audit'
        : (array_filter($modes) ? 'partial' : 'off'));
    $st['net'] = $m($h['net'] ?? null);
    $st['cfa'] = $m($h['cfa'] ?? null);
    $st['autorun'] = ((int)($h['autorun'] ?? -1)) === 255 ? 'on' : 'off';
    $st['scan'] = ((int)($h['quick_time'] ?? -1)) > 0 ? 'on' : 'off';
    return $st;
}

/** Koruma satırları: anahtar => [ad, açıklama, izleme modu var mı] */
function protection_items(): array {
    return [
        'pua' => ['PUA koruması', 'Crack/keygen, reklam yazılımı ve izinsiz paket yükleyicileri engeller.', true],
        'asr' => ['ASR kuralları (10)', "USB'den imzasız program, Office makroları, e-postadan exe, gizlenmiş betik ve parola hırsızlığına karşı.", true],
        'net' => ['Ağ koruması', 'Bilinen zararlı ve oltalama sitelerine bağlantıyı keser.', true],
        'cfa' => ['Fidye yazılımı klasör koruması', 'Belgeleri tanımadığı programlara karşı korur. Tahta yazılımlarını engelleyebilir; önce izleyin.', true],
        'autorun' => ['USB otomatik çalıştırma kapalı', 'USB takılınca kendiliğinden çalışan programları durdurur.', false],
        'scan' => ['Günlük hızlı tarama', 'Her gün 12:30\'da (tahtalar açıkken) hızlı tarama.', false],
    ];
}

/** Koruma olayı türü: [ad, mod: block/audit] */
function protection_event_label(int $id): array {
    return [1121 => ['ASR', 'block'], 1122 => ['ASR', 'audit'], 1123 => ['Klasör koruması', 'block'], 1124 => ['Klasör koruması', 'audit'],
        1125 => ['Ağ koruması', 'audit'], 1126 => ['Ağ koruması', 'block'], 1160 => ['PUA', 'audit']][$id] ?? ['Defender', 'audit'];
}

/** Ajanın gönderdiği koruma olaylarını saklar. */
function protection_events_store(int $deviceId, array $events): void {
    $ins = db()->prepare('INSERT IGNORE INTO protection_events (device_id, event_id, rule, target, process, user_name, occurred_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach (array_slice($events, 0, 50) as $e) {
        if (!is_array($e) || empty($e['time']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string)$e['time'])) continue;
        $ins->execute([$deviceId, (int)($e['event'] ?? 0), mb_substr((string)($e['rule'] ?? ''), 0, 64) ?: null,
            mb_substr((string)($e['path'] ?? ''), 0, 500) ?: null, mb_substr((string)($e['process'] ?? ''), 0, 500) ?: null,
            mb_substr((string)($e['user'] ?? ''), 0, 128) ?: null, str_replace('T', ' ', substr((string)$e['time'], 0, 19))]);
    }
}
