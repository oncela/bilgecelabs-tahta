<?php
// Kurulum dosyası: ajan exe'si değiştirilmeden gönderilir (her okul için aynı dosya,
// imzalanabilir). Kayıt anahtarı dosya adındadır:
//   BilgeceTahtaKurulum_<anahtar>.exe              (varsayılan sunucu)
//   BilgeceTahtaKurulum_<anahtar>@<alan adı>.exe   (başka sunucu, https)
// bkz. agent/cmd/aci-ajan/setup.go
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

const DEFAULT_SERVER = 'https://tahta.bilgecelabs.com'; // ajandaki defaultServer ile aynı

$exe = ACI_ROOT . '/storage/aci-ajan.exe';
if (!is_file($exe)) { http_response_code(404); exit('Ajan dosyası bulunamadı.'); }

// Kaldırma aracı: aynı exe, "BilgeceTahtaKaldir.exe" adıyla (anahtar gerekmez)
if (($_GET['tool'] ?? '') === 'kaldir') {
    audit('download', 'kaldırma aracı');
    header('Content-Type: application/vnd.microsoft.portable-executable');
    header('Content-Disposition: attachment; filename="BilgeceTahtaKaldir.exe"');
    header('Content-Length: ' . filesize($exe));
    header('Cache-Control: no-store');
    readfile($exe);
    exit;
}

$key = setting('enroll_key');
if (!$key || !preg_match('/^[0-9a-f]{32}$/', $key)) { http_response_code(500); exit('Kayıt anahtarı geçersiz.'); }

$base = rtrim(cfg('ACI_BASE_URL', DEFAULT_SERVER), '/');
$name = 'BilgeceTahtaKurulum_' . $key;
if ($base !== DEFAULT_SERVER) {
    // Ajan dosya adındaki sunucuya https ile bağlanır.
    if (!str_starts_with($base, 'https://')) { http_response_code(500); exit('Kurulum dosyası için panel adresi https olmalı (ACI_BASE_URL).'); }
    $name .= '@' . parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
}

audit('download');
header('Content-Type: application/vnd.microsoft.portable-executable');
header('Content-Disposition: attachment; filename="' . $name . '.exe"');
header('Content-Length: ' . filesize($exe));
header('Cache-Control: no-store');
readfile($exe);
