<?php
// MeshCentral ajanını tahtalara dağıtır. Dosya MeshCentral'dan bir kez
// alınıp önbellekte tutulur; tahtalar sadece panelle konuşur.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mesh.php';

device_from_token(); // sadece kayıtlı tahtalar indirebilir
if (!mesh_enabled() || cfg('ACI_MESH_GROUP') === '') json_out(['error' => 'uzaktan erişim kapalı'], 404);

$file = ACI_ROOT . '/storage/meshagent64.exe';
if (!is_file($file) || filemtime($file) < time() - 86400) {
    $meshId = substr(cfg('ACI_MESH_GROUP'), strlen('mesh//'));
    // MeshCentral aynı sunucuda: yerel adresten al (dışarı çıkıp tünelden dönmeye gerek yok).
    // Ajanın içine gömülen sunucu adresi isteğe değil MeshCentral ayarına (cert) bağlıdır.
    $local = str_replace('wss://', 'https://', cfg('ACI_MESH_WSS'));
    $base = $local !== '' ? $local : cfg('ACI_MESH_URL');
    $url = rtrim($base, '/') . '/meshagents?' . http_build_query(['id' => 4, 'meshid' => $meshId, 'installflags' => 0]);
    $host = parse_url($base, PHP_URL_HOST);
    // Kendinden imzalı sertifika: yalnız yerel adreste (veya açıkça izin verilmişse) doğrulama yapma
    $insecure = in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || cfg('ACI_MESH_INSECURE') === '1';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($data) || strncmp($data, 'MZ', 2) !== 0) {
        if (!is_file($file)) json_out(['error' => 'MeshCentral ajanı alınamadı'], 502);
    } else {
        @mkdir(dirname($file), 0750, true);
        file_put_contents($file . '.tmp', $data);
        rename($file . '.tmp', $file);
    }
}
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($file));
header('X-Sha256: ' . hash_file('sha256', $file));
readfile($file);
