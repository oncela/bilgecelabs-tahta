<?php
// Ortak alan dosyasını yalnız kayıtlı tahtalara (Bearer token) sunar.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

device_from_token(); // geçersizse 401
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT orig_name, stored_name, sha256 FROM shared_files WHERE id = ?');
$st->execute([$id]);
$f = $st->fetch();
if (!$f) { http_response_code(404); exit('yok'); }

$path = ACI_ROOT . '/storage/shared/' . $f['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('dosya yok'); }

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('X-Sha256: ' . $f['sha256']);
header('Cache-Control: no-store');
readfile($path);
