<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../views/layout.php';
require_login();

$rows = db()->query('SELECT l.created_at, l.action, l.detail, l.ip, a.username
    FROM audit_log l LEFT JOIN admins a ON a.id = l.admin_id
    ORDER BY l.id DESC LIMIT 300')->fetchAll();

$names = [
    'login' => 'Giriş', 'logout' => 'Çıkış', 'login_failed' => 'Hatalı giriş',
    'command' => 'Komut', 'device_enroll' => 'Tahta kaydı', 'device_update' => 'Tahta düzenleme',
    'device_delete' => 'Tahta silme', 'netscan' => 'Ağ taraması', 'enroll_key' => 'Kayıt anahtarı',
    'download' => 'İndirme', 'password' => 'Şifre değişikliği',
    'mesh_wake' => 'Uzaktan açma', 'mesh_sleep' => 'Uyku', 'mesh_off' => 'Kapatma (uzaktan)', 'mesh_reset' => 'Yeniden başlatma (uzaktan)',
    'mesh_open_url' => 'Web adresi açma', 'mesh_toast' => 'Bildirim', 'shared_upload' => 'Dosya yükleme', 'shared_send' => 'Dosya gönderme', 'shared_delete' => 'Dosya silme',
    'catalog_save' => 'Yazılım tanımı', 'catalog_delete' => 'Yazılım silme', 'catalog_install' => 'Yazılım kurma', 'location_save' => 'Lokasyon', 'assign_location' => 'Lokasyon atama', 'alert_resolve' => 'Uyarı çözme', 'auto_update' => 'Otomatik güncelleme', 'notify_settings' => 'Bildirim ayarı',
    'remote_desktop' => 'Ekran açıldı', 'remote_files' => 'Dosyalar açıldı', 'remote_terminal' => 'Komut satırı açıldı',
];
page_header('İşlem geçmişi', 'history.php');
?>
<p class="text-secondary">Son 300 kayıt</p>
<div class="card">
  <div class="table-responsive">
  <table class="table table-vcenter card-table">
    <thead><tr><th>Zaman</th><th>Kişi</th><th>İşlem</th><th>Ayrıntı</th><th>IP</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr class="empty-row"><td colspan="5">Kayıt yok.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap mono"><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
        <td><?= h($r['username'] ?? '—') ?></td>
        <td><span class="badge <?= $r['action'] === 'login_failed' ? 'bg-danger-lt' : 'bg-secondary-lt' ?>"><?= h($names[$r['action']] ?? $r['action']) ?></span></td>
        <td class="text-break" style="max-width: 520px"><?= h($r['detail']) ?></td>
        <td class="mono"><?= h($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php page_footer();
