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
<link rel="stylesheet" href="vendor/tabler/css/tabler.min.css">
<link rel="stylesheet" href="assets/aci.css?v=<?= h(substr(md5((string)@filemtime(__DIR__ . '/assets/aci.css')), 0, 8)) ?>">
</head>
<body class="d-flex flex-column">
<div class="page page-center">
  <div class="container container-tight py-4">
    <div class="text-center mb-4">
      <span class="h2"><span class="login-brand"></span><?= h(ACI_PRODUCT) ?></span>
      <div class="text-secondary"><?= h(school_name()) ?> · Akıllı tahta yönetim paneli</div>
    </div>
    <form method="post" class="card card-md" autocomplete="on">
      <div class="card-body">
        <h2 class="h3 text-center mb-4">Hesabınıza giriş yapın</h2>
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="username">Kullanıcı adı</label>
          <input id="username" name="username" class="form-control" autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Şifre</label>
          <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Giriş yap</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
