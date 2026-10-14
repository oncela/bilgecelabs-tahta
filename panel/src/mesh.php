<?php
// MeshCentral entegrasyonu: panelin içinde tahta ekranı açmak için
// tek kullanımlık, kısa süreli giriş anahtarı üretir.
// Biçim MeshCentral'ın encodeCookie() işleviyle aynıdır (AES-256-GCM).
declare(strict_types=1);

const MESH_VIEWS = [
    'desktop'  => [11, 'Ekran'],
    'terminal' => [12, 'Komut satırı'],
    'files'    => [13, 'Dosyalar'],
];

function mesh_enabled(): bool {
    return cfg('ACI_MESH_URL') !== '' && strlen(cfg('ACI_MESH_LOGIN_KEY')) === 160;
}

/** $meshUser: "panel-test" gibi MeshCentral kullanıcı adı. */
function mesh_login_token(string $meshUser): string {
    $key = substr(hex2bin(cfg('ACI_MESH_LOGIN_KEY')), 0, 32);
    $payload = json_encode([
        'u' => 'user//' . strtolower($meshUser),
        'a' => 3,
        'time' => time(),
        'expire' => 2,                         // dakika
        'once' => bin2hex(random_bytes(12)),   // tek kullanımlık
    ]);
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('Giriş anahtarı üretilemedi');
    return strtr(base64_encode($iv . $tag . $ct), ['+' => '@', '/' => '$']);
}

/**
 * Tahtanın MeshCentral sayfası; MeshCentral menüleri gizli (hide=31).
 * Ajanın bildirdiği cihaz kimliği varsa onunla (kesin), yoksa bilgisayar adıyla açılır.
 */
function mesh_device_url(string $meshUser, array $device, string $view): string {
    $mode = MESH_VIEWS[$view][0] ?? 11;
    $target = ($device['mesh_node_id'] ?? '') !== ''
        ? ['gotonode' => $device['mesh_node_id']]
        : ['gotodevicename' => $device['hostname']];
    // /login: giriş yapmamış ziyaretçiyi panele yönlendiren kural (unknownUserRootRedirect) bu yolu muaf tutar
    return rtrim(cfg('ACI_MESH_URL'), '/') . '/login?' . http_build_query(['login' => mesh_login_token($meshUser)]
        + $target + ['viewmode' => $mode, 'hide' => 31]);
}
