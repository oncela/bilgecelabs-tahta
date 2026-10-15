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
    } elseif (($_POST['do'] ?? '') === 'auto_update') {
        setting_set('auto_update', isset($_POST['auto_update']) ? '1' : '0');
        audit('auto_update', isset($_POST['auto_update']) ? 'açık' : 'kapalı');
        $msg = 'Otomatik güncelleme ayarı kaydedildi.';
    } elseif (($_POST['do'] ?? '') === 'notify') {
        foreach (['notify_telegram_token', 'notify_telegram_chat', 'notify_webhook', 'notify_email', 'notify_email_from',
                  'alert_disk_warn', 'alert_disk_crit', 'alert_mem_warn', 'alert_offline_hours'] as $k) {
            setting_set($k, trim((string)($_POST[$k] ?? '')));
        }
        setting_set('alert_bitlocker', isset($_POST['alert_bitlocker']) ? '1' : '0');
        audit('notify_settings', 'güncellendi');
        $msg = 'Bildirim ayarları kaydedildi.';
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
  <p>Kurulum dosyasını cihazda indirip çift tıklayın. Sihirbaz açılır: cihaz türünü (akıllı tahta, sınıf bilgisayarı, idare, danışma) ve konumunu seçin, kurulum birkaç saniye sürer. Cihaz kısa süre içinde <a href="devices.php">Tahtalar</a> sayfasında görünür.</p>
  <?php if ($hasAgent): ?>
    <p><a class="btn btn-primary" href="download.php">Kurulum dosyasını indir (BilgeceTahtaKurulum.exe)</a></p>
    <p class="text-secondary small">Tahtadan indirmek için tarayıcıda bu panele giriş yapıp bu sayfayı açın. Dosyanın adında okulun kayıt anahtarı bulunur; dosya adını değiştirmeyin ve panel dışında paylaşmayın.</p>
    <p class="mb-0"><a class="btn" href="download.php?tool=kaldir"><i class="ti ti-trash me-1"></i>Kaldırma aracını indir (BilgeceTahtaKaldir.exe)</a></p>
    <p class="text-secondary small mt-2 mb-0">Ajanı ve uzaktan erişimi o bilgisayardan kaldırır. Panelden uzaktan kaldırmak için tahtaya tıklayıp "Tahtadan kaldır" deyin.</p>
  <?php else: ?>
    <div class="alert alert-warning">Sunucuda ajan dosyası yok: <code>storage/aci-ajan.exe</code> yüklenmeli.</div>
  <?php endif; ?>
  <hr>
  <form method="post" class="d-flex align-items-center gap-2">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="auto_update">
    <label class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="auto_update" value="1" onchange="this.form.submit()" <?= (setting('auto_update', '1') === '1') ? 'checked' : '' ?>><span class="form-check-label">Uzaktan otomatik güncelleme (cihazlar yeni sürümü kendiliğinden kurar)</span></label>
  </form>
  <p class="text-secondary small mb-0 mt-1">Sunucudaki ajan sürümü: <code><?= h(agent_version()) ?></code></p>
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
  <h3 class="card-title">Uyarı bildirimleri</h3>
  <p class="text-secondary">Yeni uyarı oluştuğunda (disk dolu, çevrimdışı, virüs koruması kapalı…) bildirim gönderilir.</p>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="notify">
    <div class="col-md-6"><label class="form-label">Telegram bot token</label>
      <input class="form-control" name="notify_telegram_token" value="<?= h((string)setting('notify_telegram_token')) ?>" placeholder="123456:ABC..."></div>
    <div class="col-md-6"><label class="form-label">Telegram sohbet/kanal ID</label>
      <input class="form-control" name="notify_telegram_chat" value="<?= h((string)setting('notify_telegram_chat')) ?>" placeholder="-100123..."></div>
    <div class="col-md-6"><label class="form-label">Webhook adresi</label>
      <input class="form-control" name="notify_webhook" value="<?= h((string)setting('notify_webhook')) ?>" placeholder="https://..."></div>
    <div class="col-md-6"><label class="form-label">E-posta (alıcı)</label>
      <input class="form-control" name="notify_email" value="<?= h((string)setting('notify_email')) ?>" placeholder="yonetici@firma.com"></div>
    <div class="col-12"><hr class="my-1"><span class="text-secondary small">Eşik değerleri</span></div>
    <div class="col-md-3"><label class="form-label">Disk uyarı %</label>
      <input class="form-control" type="number" name="alert_disk_warn" value="<?= h((string)(setting('alert_disk_warn') ?? '90')) ?>" min="50" max="99"></div>
    <div class="col-md-3"><label class="form-label">Disk kritik %</label>
      <input class="form-control" type="number" name="alert_disk_crit" value="<?= h((string)(setting('alert_disk_crit') ?? '95')) ?>" min="50" max="100"></div>
    <div class="col-md-3"><label class="form-label">Bellek uyarı %</label>
      <input class="form-control" type="number" name="alert_mem_warn" value="<?= h((string)(setting('alert_mem_warn') ?? '92')) ?>" min="50" max="100"></div>
    <div class="col-md-3"><label class="form-label">Çevrimdışı (saat)</label>
      <input class="form-control" type="number" name="alert_offline_hours" value="<?= h((string)(setting('alert_offline_hours') ?? '24')) ?>" min="1" max="720"></div>
    <div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" name="alert_bitlocker" value="1" <?= (setting('alert_bitlocker') ?? '0') === '1' ? 'checked' : '' ?>><span class="form-check-label">BitLocker (disk şifreleme) kapalıysa uyarı oluştur</span></label></div>
    <div class="col-12"><button class="btn btn-primary">Kaydet</button></div>
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
<p class="text-secondary small mt-3"><?= h(ACI_PRODUCT) ?> <?= h(trim((string)@file_get_contents(ACI_ROOT . '/storage/aci-ajan.version')) ?: '') ?> · <a href="licenses.php" class="text-secondary">Üçüncü taraf lisansları</a></p>
<?php page_footer();
