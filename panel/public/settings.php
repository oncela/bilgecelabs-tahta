<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_once __DIR__ . '/../src/health.php';
require_login();

$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'rotate_key') {
        setting_set('enroll_key', bin2hex(random_bytes(16)));
        audit('enroll_key', 'yenilendi');
        $msg = 'Yeni kayıt anahtarı oluşturuldu. Eski kurulum dosyaları artık yeni tahta kaydedemez; kayıtlı tahtalar etkilenmez.';
    } elseif (($_POST['do'] ?? '') === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $st = db()->prepare('SELECT pass_hash FROM admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        if (!password_verify($cur, (string)$st->fetchColumn())) {
            $err = 'Mevcut şifre hatalı.';
        } elseif (mb_strlen($new) < 10) {
            $err = 'Yeni şifre en az 10 karakter olmalı.';
        } else {
            db()->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
            audit('password');
            $msg = 'Şifreniz değiştirildi.';
        }
    }
}
$key = setting('enroll_key') ?? '';
$hasAgent = is_file(ACI_ROOT . '/storage/aci-ajan.exe');
page_header('Ayarlar', 'settings.php');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="row row-cards">
<?php $checks = health_checks(); $problems = count(array_filter($checks, fn($c) => $c['level'] === 'bad')); ?>
<div class="col-12"><section class="card">
  <div class="card-header">
    <h3 class="card-title">Sistem durumu</h3>
    <span class="badge ms-auto <?= $problems ? 'bg-danger-lt' : 'bg-success-lt' ?>"><?= $problems ? $problems . ' sorun' : 'Her şey çalışıyor' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter card-table">
      <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td class="w-1"><i class="ti <?= ['ok' => 'ti-circle-check text-success', 'warn' => 'ti-alert-triangle text-warning', 'bad' => 'ti-circle-x text-danger'][$c['level']] ?>"></i></td>
          <td class="fw-medium text-nowrap"><?= h($c['name']) ?></td>
          <td class="text-secondary"><?= h($c['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section></div>

<div class="col-12"><section class="card"><div class="card-body">
  <h3 class="card-title">Tahtaya kurulum</h3>
  <p>Kurulum dosyasını tahtada indirip çift tıklayın. Windows yönetici izni isteyecek, kurulum birkaç saniye sürer. Tahta kısa süre içinde <a href="devices.php">Tahtalar</a> sayfasında görünür.</p>
  <?php if ($hasAgent): ?>
    <p><a class="btn btn-primary" href="download.php">Kurulum dosyasını indir (AciTahtaKurulum.exe)</a></p>
    <p class="text-secondary small">Tahtadan indirmek için tarayıcıda bu panele giriş yapıp bu sayfayı açın. Dosyanın adında okulun kayıt anahtarı bulunur; dosya adını değiştirmeyin ve panel dışında paylaşmayın.</p>
    <p class="mb-0"><a class="btn" href="download.php?tool=kaldir"><i class="ti ti-trash me-1"></i>Kaldırma aracını indir (AciTahtaKaldir.exe)</a></p>
    <p class="text-secondary small mt-2 mb-0">Ajanı ve uzaktan erişimi o bilgisayardan kaldırır. Panelden uzaktan kaldırmak için tahtaya tıklayıp "Tahtadan kaldır" deyin.</p>
  <?php else: ?>
    <div class="alert alert-warning">Sunucuda ajan dosyası yok: <code>storage/aci-ajan.exe</code> yüklenmeli.</div>
  <?php endif; ?>
</div></section></div>

<div class="col-12"><section class="card"><div class="card-body">
  <h3 class="card-title">Kayıt anahtarı</h3>
  <p class="text-secondary">Yeni tahtaların panele kaydolması için kullanılır. Kurulum dosyası sızdırıldıysa yenileyin.</p>
  <p><code><?= h(substr($key, 0, 6)) ?>…<?= h(substr($key, -4)) ?></code></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="rotate_key">
    <button class="btn">Anahtarı yenile</button>
  </form>
</div></section></div>

<div class="col-12"><section class="card"><div class="card-body">
  <h3 class="card-title">Şifre değiştir</h3>
  <form method="post" style="max-width: 360px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="password">
    <label class="form-label" for="current">Mevcut şifre</label>
    <input class="form-control" id="current" name="current" type="password" autocomplete="current-password" required>
    <label class="form-label mt-2" for="new">Yeni şifre (en az 10 karakter)</label>
    <input class="form-control" id="new" name="new" type="password" autocomplete="new-password" minlength="10" required>
    <button class="btn btn-primary mt-3">Şifreyi değiştir</button>
  </form>
</div></section></div>
</div>
<?php page_footer();
