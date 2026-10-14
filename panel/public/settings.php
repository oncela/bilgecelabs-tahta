<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
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
<div class="head"><h1>Ayarlar</h1></div>
<?php if ($msg): ?><p class="notice"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="alert"><?= h($err) ?></p><?php endif; ?>

<section class="card pad">
  <h2>Tahtaya kurulum</h2>
  <p>Kurulum dosyasını tahtada indirip çift tıklayın. Windows yönetici izni isteyecek, kurulum birkaç saniye sürer. Tahta kısa süre içinde <a href="devices.php">Tahtalar</a> sayfasında görünür.</p>
  <?php if ($hasAgent): ?>
    <p><a class="btn primary" href="download.php">Kurulum dosyasını indir (AciTahtaKurulum.exe)</a></p>
    <p class="muted small">Tahtadan indirmek için tarayıcıda bu panele giriş yapıp bu sayfayı açın. Dosyanın içinde okulun kayıt anahtarı bulunur; panel dışında paylaşmayın.</p>
  <?php else: ?>
    <p class="alert">Sunucuda ajan dosyası yok: <code>storage/aci-ajan.exe</code> yüklenmeli.</p>
  <?php endif; ?>
</section>

<section class="card pad">
  <h2>Kayıt anahtarı</h2>
  <p class="muted">Yeni tahtaların panele kaydolması için kullanılır. Kurulum dosyası sızdırıldıysa yenileyin.</p>
  <p><code class="key"><?= h(substr($key, 0, 6)) ?>…<?= h(substr($key, -4)) ?></code></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="rotate_key">
    <button class="btn">Anahtarı yenile</button>
  </form>
</section>

<section class="card pad">
  <h2>Şifre değiştir</h2>
  <form method="post" class="narrow">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="password">
    <label for="current">Mevcut şifre</label>
    <input id="current" name="current" type="password" autocomplete="current-password" required>
    <label for="new">Yeni şifre (en az 10 karakter)</label>
    <input id="new" name="new" type="password" autocomplete="new-password" minlength="10" required>
    <button class="btn primary">Şifreyi değiştir</button>
  </form>
</section>
<?php page_footer();
