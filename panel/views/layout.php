<?php
declare(strict_types=1);

function page_header(string $title, string $active = ''): void {
    $nav = [
        'devices.php'  => 'Tahtalar',
        'network.php'  => 'Ağ',
        'history.php'  => 'Geçmiş',
        'settings.php' => 'Ayarlar',
    ];
    ?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h($title) ?> · Acı Tahta</title>
<link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body>
<header class="top">
  <a class="brand" href="devices.php">Acı Tahta</a>
  <nav>
    <?php foreach ($nav as $href => $label): ?>
      <a href="<?= $href ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <a class="out" href="logout.php">Çıkış</a>
</header>
<main class="wrap">
<?php
}

function page_footer(): void {
    ?>
</main>
<script src="assets/app.js?v=2"></script>
</body>
</html>
<?php
}
