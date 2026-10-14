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
    $url = rtrim(cfg('ACI_MESH_URL'), '/') . '/meshagents?' . http_build_query(['id' => 4, 'meshid' => $meshId, 'installflags' => 0]);
    $insecure = cfg('ACI_MESH_INSECURE') === '1'; // sadece yerel test (kendinden imzalı sertifika)
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
