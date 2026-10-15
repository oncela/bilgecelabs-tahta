<?php
// Yeni ajan sürümünü yalnız kayıtlı cihazlara (Bearer token) sunar (uzaktan güncelleme).
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
device_from_token(); // geçersizse 401

$exe = ACI_ROOT . '/storage/aci-ajan.exe';
if (!is_file($exe)) { http_response_code(404); exit('yok'); }
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($exe));
header('X-Sha256: ' . hash_file('sha256', $exe));
header('X-Version: ' . agent_version());
header('Cache-Control: no-store');
readfile($exe);
