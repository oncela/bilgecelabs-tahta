<?php
// Kurulum dosyası: ajan exe'sinin sonuna sunucu adresi ve kayıt anahtarı eklenir.
// Biçim: [exe][json][uzunluk: uint64 LE]["ACICFG01"]  (bkz. agent/cmd/aci-ajan/embedded.go)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$exe = ACI_ROOT . '/storage/aci-ajan.exe';
if (!is_file($exe)) { http_response_code(404); exit('Ajan dosyası bulunamadı.'); }

$key = setting('enroll_key');
if (!$key) { http_response_code(500); exit('Kayıt anahtarı yok.'); }

$server = rtrim(cfg('ACI_BASE_URL', 'https://tahta.bilgecelabs.com'), '/');
$json = json_encode(['server' => $server, 'key' => $key], JSON_UNESCAPED_SLASHES);
$trailer = $json . pack('P', strlen($json)) . 'ACICFG01';

audit('download');
header('Content-Type: application/vnd.microsoft.portable-executable');
header('Content-Disposition: attachment; filename="AciTahtaKurulum.exe"');
header('Content-Length: ' . (filesize($exe) + strlen($trailer)));
header('Cache-Control: no-store');
readfile($exe);
echo $trailer;
