<?php
// Kullanım: php tools/create_admin.php KULLANICI
// Şifre terminalden sorulur (komut geçmişinde görünmesin diye).
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') exit(1);
$user = $argv[1] ?? '';
if ($user === '') { fwrite(STDERR, "Kullanım: php tools/create_admin.php KULLANICI\n"); exit(2); }

fwrite(STDOUT, "Şifre: ");
system('stty -echo');
$pass = trim((string)fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\n");
if (mb_strlen($pass) < 10) { fwrite(STDERR, "Şifre en az 10 karakter olmalı.\n"); exit(1); }

db()->prepare('INSERT INTO admins (username, pass_hash) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash)')
    ->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
if (setting('enroll_key') === null) setting_set('enroll_key', bin2hex(random_bytes(16)));
echo "Yönetici kaydedildi: $user\n";
