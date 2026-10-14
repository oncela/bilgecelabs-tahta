<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
session_begin();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT id, pass_hash FROM admins WHERE username = ?');
    $st->execute([$user]);
    $row = $st->fetch();
    if ($row && password_verify($pass, $row['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_name'] = $user;
        db()->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
        audit('login');
        header('Location: devices.php');
        exit;
    }
    sleep(1); // kaba kuvvet denemelerini yavaşlat
    db()->prepare("INSERT INTO audit_log (action, detail, ip) VALUES ('login_failed', ?, ?)")
        ->execute([mb_substr($user, 0, 64), client_ip()]);
    $error = 'Kullanıcı adı veya şifre hatalı.';
}
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Giriş · Acı Tahta</title>
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body class="login-page">
<form method="post" class="login card">
  <h1>Acı Tahta</h1>
  <p class="muted">Akıllı tahta yönetim paneli</p>
  <?php if ($error): ?><p class="alert"><?= h($error) ?></p><?php endif; ?>
  <label for="username">Kullanıcı adı</label>
  <input id="username" name="username" autocomplete="username" required autofocus>
  <label for="password">Şifre</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <button type="submit" class="btn primary">Giriş yap</button>
</form>
</body>
</html>
