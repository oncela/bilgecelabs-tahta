<?php
// Yeni ajan (ve self servis) sürümünü yalnız kayıtlı cihazlara (Bearer token) sunar.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
device_from_token(); // geçersizse 401

// ?dosya=destek: self servis penceresi (bilgece-destek.exe), yoksa ajan
$destek = ($_GET['dosya'] ?? '') === 'destek';
$exe = ACI_ROOT . ($destek ? '/storage/bilgece-destek.exe' : '/storage/aci-ajan.exe');
if (!is_file($exe)) { http_response_code(404); exit('yok'); }
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($exe));
header('X-Sha256: ' . hash_file('sha256', $exe));
header('X-Version: ' . agent_version());
header('Cache-Control: no-store');
readfile($exe);
