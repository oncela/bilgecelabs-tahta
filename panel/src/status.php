<?php
// Tahta durumlarını panelde gösterilecek tek bir göstergeye indirger.
declare(strict_types=1);

/** Uzaktan erişim (MeshCentral ajanı) durumu. */
function mesh_summary(string $s): array {
    return match (true) {
        $s === 'running' => ['state' => 'ok', 'text' => 'Hazır'],
        $s === 'installing' => ['state' => 'warn', 'text' => 'Kuruluyor'],
        $s === 'stopped' => ['state' => 'warn', 'text' => 'Durmuş, başlatılıyor'],
        str_starts_with($s, 'error') => ['state' => 'bad', 'text' => 'Kurulamadı: ' . mb_substr($s, 7, 120)],
        default => ['state' => 'off', 'text' => 'Kurulu değil'],
    };
}

/** Defender durumunu tek bir göstergeye indirger. */
function defender_summary(?string $json): array {
    $d = $json ? json_decode($json, true) : null;
    if (!is_array($d)) return ['state' => 'unknown', 'text' => 'Bilgi yok'];
    if (isset($d['error'])) return ['state' => 'unknown', 'text' => 'Okunamadı'];
    // Başka antivirüs (ör. Bitdefender) etkinse Defender kendini kapatır: cihaz yine korumalıdır
    $other = array_values(array_filter((array)($d['av'] ?? []), fn($a) => is_array($a) && !empty($a['on'])));
    if ($other && (empty($d['enabled']) || empty($d['realtime']))) {
        $name = mb_substr((string)($other[0]['name'] ?? 'Diğer antivirüs'), 0, 40);
        return empty($other[0]['uptodate'])
            ? ['state' => 'warn', 'text' => "$name: imzalar eski"]
            : ['state' => 'ok', 'text' => "$name ile korunuyor"];
    }
    if (empty($d['enabled']) || empty($d['realtime'])) return ['state' => 'bad', 'text' => 'Koruma kapalı'];
    if (($d['threats'] ?? 0) > 0) return ['state' => 'bad', 'text' => $d['threats'] . ' tehdit'];
    if (($d['sig_age_days'] ?? 99) > 3) return ['state' => 'warn', 'text' => 'İmza ' . $d['sig_age_days'] . ' günlük'];
    return ['state' => 'ok', 'text' => 'Korumalı'];
}
