<?php
declare(strict_types=1);

/** Yan menü: [dosya, başlık, simge, hazır mı]. Gruplar sırayla gösterilir. */
function menu_items(): array {
    return [
        'Günlük' => [
            ['dashboard.php', 'Genel bakış', 'home', true],
            ['rooms.php', 'Sınıflar', 'school', true],
            ['devices.php', 'Tahtalar', 'device-desktop', true],
            ['schedule.php', 'Zamanlama', 'calendar-time', false],
        ],
        'Yönetim' => [
            ['software.php', 'Yazılımlar', 'apps', false],
            ['shared.php', 'Ortak alan', 'folder', false],
            ['security.php', 'Güvenlik', 'shield-check', false],
            ['network.php', 'Ağ', 'network', true],
        ],
        'Sistem' => [
            ['history.php', 'Geçmiş', 'history', true],
            ['settings.php', 'Ayarlar', 'settings', true],
        ],
    ];
}

function page_header(string $title, string $active = ''): void {
    $user = $_SESSION['admin_name'] ?? '';
    ?><!doctype html>
<html lang="tr" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h($title) ?> · <?= h(ACI_PRODUCT) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/brand/favicon.svg">
<link rel="icon" href="assets/brand/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="assets/brand/apple-touch-icon.png">
<link rel="stylesheet" href="vendor/tabler/css/tabler.min.css">
<link rel="stylesheet" href="vendor/tabler-icons/tabler-icons.min.css">
<link rel="stylesheet" href="assets/aci.css?v=<?= h(asset_version()) ?>">
</head>
<body>
<div class="page">
  <aside class="navbar navbar-vertical navbar-expand-lg aci-sidebar">
    <div class="container-fluid">
      <button class="navbar-toggler text-white" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Menüyü aç">
        <i class="ti ti-menu-2"></i>
      </button>
      <a class="navbar-brand navbar-brand-autodark flex-column align-items-start" href="dashboard.php">
        <span class="d-flex align-items-center"><img src="assets/brand/mark-light.svg" alt="" class="brand-logo"><?= h(ACI_PRODUCT) ?></span>
        <span class="brand-school"><?= h(school_name()) ?></span>
      </a>
      <div class="collapse navbar-collapse" id="sidebar-menu">
        <ul class="navbar-nav pt-lg-2">
          <?php foreach (menu_items() as $group => $items): ?>
            <li class="menu-group"><?= h($group) ?></li>
            <?php foreach ($items as [$href, $label, $icon, $ready]): ?>
              <li class="nav-item <?= $active === $href ? 'active' : '' ?>">
                <?php if ($ready): ?>
                  <a class="nav-link" href="<?= h($href) ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>>
                    <span class="nav-link-icon"><i class="ti ti-<?= h($icon) ?>"></i></span>
                    <span class="nav-link-title"><?= h($label) ?></span>
                  </a>
                <?php else: ?>
                  <span class="nav-link disabled" aria-disabled="true">
                    <span class="nav-link-icon"><i class="ti ti-<?= h($icon) ?>"></i></span>
                    <span class="nav-link-title"><?= h($label) ?></span>
                    <span class="soon">Yakında</span>
                  </span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </aside>
  <div class="page-wrapper">
    <header class="navbar navbar-expand-md d-print-none border-bottom">
      <div class="container-xl">
        <form class="me-auto" action="devices.php" method="get" role="search" style="max-width: 360px; width: 100%;">
          <div class="input-icon">
            <span class="input-icon-addon"><i class="ti ti-search"></i></span>
            <input type="search" name="q" class="form-control" placeholder="Sınıf, tahta veya IP ara" aria-label="Ara" value="<?= h($_GET['q'] ?? '') ?>">
          </div>
        </form>
        <div class="navbar-nav flex-row order-md-last ms-3">
          <div class="nav-item dropdown">
            <a href="#" class="nav-link d-flex lh-1 p-0 px-2" data-bs-toggle="dropdown" aria-label="Kullanıcı menüsü">
              <span class="avatar avatar-sm bg-primary-lt text-primary"><?= h(mb_strtoupper(mb_substr($user, 0, 2))) ?></span>
              <span class="d-none d-xl-block ps-2"><?= h($user) ?></span>
            </a>
            <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
              <a href="settings.php" class="dropdown-item"><i class="ti ti-settings me-2"></i>Ayarlar</a>
              <a href="logout.php" class="dropdown-item"><i class="ti ti-logout me-2"></i>Çıkış</a>
            </div>
          </div>
        </div>
      </div>
    </header>
    <div class="page-header d-print-none">
      <div class="container-xl">
        <h2 class="page-title"><?= h($title) ?></h2>
      </div>
    </div>
    <div class="page-body">
      <div class="container-xl">
<?php
}

/** $scripts: yalnız bu sayfada gereken ek kütüphaneler (app.js'ten önce yüklenir). */
function page_footer(array $scripts = []): void {
    ?>
      </div>
    </div>
  </div>
</div>
<script src="vendor/tabler/js/tabler.min.js"></script>
<?php foreach ($scripts as $src): ?><script src="<?= h($src) ?>"></script>
<?php endforeach; ?>
<script src="assets/app.js?v=<?= h(asset_version()) ?>"></script>
</body>
</html>
<?php
}

/** Önbellek kırma: dosyalar değişince tarayıcı yenisini alır. */
function asset_version(): string {
    $dir = __DIR__ . '/../public/assets/';
    return substr(md5((string)@filemtime($dir . 'aci.css') . (string)@filemtime($dir . 'app.js')), 0, 8);
}
