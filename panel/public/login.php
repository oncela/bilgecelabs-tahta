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
        header('Location: dashboard.php');
        exit;
    }
    sleep(1); // kaba kuvvet denemelerini yavaşlat
    db()->prepare("INSERT INTO audit_log (action, detail, ip) VALUES ('login_failed', ?, ?)")
        ->execute([mb_substr($user, 0, 64), client_ip()]);
    $error = 'Kullanıcı adı veya şifre hatalı.';
}
?><!doctype html>
<html lang="tr" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Giriş · <?= h(ACI_PRODUCT) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/brand/favicon.svg">
<link rel="icon" href="assets/brand/favicon.ico" sizes="any">
<link rel="stylesheet" href="vendor/tabler/css/tabler.min.css">
<link rel="stylesheet" href="assets/aci.css?v=<?= h(substr(md5((string)@filemtime(__DIR__ . '/assets/aci.css')), 0, 8)) ?>">
</head>
<body class="login-body">
<main class="login-split">
  <section class="login-side" aria-hidden="true">
    <img src="assets/brand/mark-light.svg" alt="" class="login-mark">
    <div class="login-wall">
      <?php foreach ([1,1,1,0,1,1,1,1,1,0,1,1,1,1,0,1,1,1,1,1,1,0,1,1] as $i => $on): ?>
        <span class="tile <?= $on ? 'is-on' : 'is-off' ?>" style="--i: <?= $i ?>"><span class="tile-screen"></span></span>
      <?php endforeach; ?>
    </div>
    <p class="login-tagline">Okulunuzdaki her tahta,<br>tek ekranda.</p>
  </section>
  <section class="login-main">
    <form method="post" class="login-form" autocomplete="on">
      <img src="assets/brand/logo.svg" alt="BilgeceLabs" class="login-logo">
      <h1 class="login-title"><?= h(ACI_PRODUCT) ?></h1>
      <p class="text-secondary mb-4"><?= h(school_name()) ?> yönetim paneli</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
      <div class="mb-3">
        <label class="form-label" for="username">Kullanıcı adı</label>
        <input id="username" name="username" class="form-control form-control-lg" autocomplete="username" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label" for="password">Şifre</label>
        <input id="password" name="password" type="password" class="form-control form-control-lg" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-lg w-100">Giriş yap</button>
    </form>
  </section>
</main>
</body>
</html>
