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
    'download' => 'Kurulum indirme', 'password' => 'Şifre değişikliği',
];
page_header('Geçmiş', 'history.php');
?>
<div class="head"><h1>İşlem geçmişi</h1><div class="counts">Son 300 kayıt</div></div>
<div class="table-wrap card">
  <table class="grid">
    <thead><tr><th>Zaman</th><th>Kişi</th><th>İşlem</th><th>Ayrıntı</th><th>IP</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">Kayıt yok.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap mono"><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
        <td><?= h($r['username'] ?? '—') ?></td>
        <td><span class="tag <?= $r['action'] === 'login_failed' ? 'bad' : '' ?>"><?= h($names[$r['action']] ?? $r['action']) ?></span></td>
        <td class="detail"><?= h($r['detail']) ?></td>
        <td class="mono"><?= h($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php page_footer();
