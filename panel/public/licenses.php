<?php
// Üçüncü taraf yazılım lisansları (açık kaynak lisans yükümlülükleri gereği).
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();

$items = [
    ['MeshCentral ve MeshAgent', 'Uzaktan erişim altyapısı', 'Apache License 2.0', 'https://github.com/Ylianst/MeshCentral'],
    ['Tabler', 'Arayüz bileşenleri', 'MIT', 'https://github.com/tabler/tabler'],
    ['Tabler Icons', 'Simgeler', 'MIT', 'https://github.com/tabler/tabler-icons'],
    ['golang.org/x/sys', 'Windows sistem çağrıları (ajan)', 'BSD 3-Clause', 'https://cs.opensource.google/go/x/sys'],
    ['Go', 'Ajanın derlendiği dil ve standart kütüphane', 'BSD 3-Clause', 'https://go.dev/LICENSE'],
];
page_header('Üçüncü taraf lisansları', 'settings.php');
?>
<div class="card">
  <div class="card-body">
    <p class="text-secondary"><?= h(ACI_PRODUCT) ?>, aşağıdaki açık kaynak yazılımları kullanır. Lisans metinleri ilgili projelerin sayfalarındadır.</p>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <thead><tr><th>Yazılım</th><th>Kullanım</th><th>Lisans</th><th>Kaynak</th></tr></thead>
      <tbody>
      <?php foreach ($items as [$name, $use, $license, $url]): ?>
        <tr>
          <td class="fw-medium"><?= h($name) ?></td>
          <td class="text-secondary"><?= h($use) ?></td>
          <td><?= h($license) ?></td>
          <td><a href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h(parse_url($url, PHP_URL_HOST)) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer();
