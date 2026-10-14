<?php
// Sistem sağlık kontrolü: panelin çalışması için gereken her parçayı denetler.
// Ayarlar sayfasında gösterilir; api/health.php izleme için özet döner.
declare(strict_types=1);

require_once __DIR__ . '/mesh.php';
require_once __DIR__ . '/meshctl.php';

/** Her kontrol: ['name' => ad, 'ok' => bool, 'level' => ok|warn|bad, 'detail' => açıklama] */
function health_checks(): array {
    $c = [];
    $add = function (string $name, string $level, string $detail) use (&$c) {
        $c[] = ['name' => $name, 'ok' => $level === 'ok', 'level' => $level, 'detail' => $detail];
    };

    // Veritabanı
    try {
        db()->query('SELECT 1');
        $add('Veritabanı', 'ok', 'Bağlantı var');
    } catch (Throwable $e) {
        $add('Veritabanı', 'bad', 'Bağlanılamadı');
        return $c; // diğer kontroller veritabanına bağlı
    }

    // Veritabanı güncellemeleri
    $files = array_map(fn($f) => basename($f, '.sql'), glob(ACI_ROOT . '/migrations/*.sql') ?: []);
    try {
        $done = db()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $done = [];
    }
    $pending = array_values(array_diff($files, $done));
    $pending
        ? $add('Veritabanı güncellemeleri', 'bad', 'Uygulanmamış: ' . implode(', ', $pending) . ' (make migrate)')
        : $add('Veritabanı güncellemeleri', 'ok', count($files) . ' güncelleme uygulanmış');

    // Kayıt anahtarı
    setting('enroll_key')
        ? $add('Kayıt anahtarı', 'ok', 'Tanımlı')
        : $add('Kayıt anahtarı', 'bad', 'Tanımlı değil; yeni tahta kaydedilemez');

    // Ajan dosyası
    $exe = ACI_ROOT . '/storage/aci-ajan.exe';
    $verFile = ACI_ROOT . '/storage/aci-ajan.version';
    if (!is_file($exe)) {
        $add('Ajan kurulum dosyası', 'bad', 'storage/aci-ajan.exe yok (make release)');
    } else {
        $ver = is_file($verFile) ? trim((string)file_get_contents($verFile)) : 'bilinmiyor';
        $add('Ajan kurulum dosyası', 'ok', 'Sürüm ' . $ver . ', ' . round(filesize($exe) / 1048576, 1) . ' MB');
    }

    // Panel adresi (kurulum dosyasına gömülen)
    $base = cfg('ACI_BASE_URL');
    if ($base === '') $add('Panel adresi', 'bad', 'ACI_BASE_URL tanımlı değil');
    elseif (!str_starts_with($base, 'https://')) $add('Panel adresi', 'warn', "$base (yerel test; yayında https olmalı)");
    else $add('Panel adresi', 'ok', $base);

    // MeshCentral: giriş anahtarı ve komut kanalı
    mesh_enabled()
        ? $add('Uzaktan erişim giriş anahtarı', 'ok', 'Tanımlı (' . cfg('ACI_MESH_URL') . ')')
        : $add('Uzaktan erişim giriş anahtarı', 'bad', 'ACI_MESH_URL veya ACI_MESH_LOGIN_KEY eksik');
    if (!meshctl_enabled()) {
        $add('Uzaktan erişim komut kanalı', 'warn', 'Ayarlanmamış; uzaktan açma ve web adresi açma çalışmaz');
    } else {
        // Panel kullanıcısı tahta grubunu görebiliyor mu? (sunucu yöneticisi yetkisi gerekmez)
        $r = meshctl(['listdevicegroups', '--json'], 15);
        $groups = json_decode($r['out'], true);
        $group = cfg('ACI_MESH_GROUP');
        $found = is_array($groups) && in_array($group, array_column($groups, '_id'), true);
        if ($found) $add('Uzaktan erişim komut kanalı', 'ok', 'MeshCentral bağlantısı var, tahta grubu erişilebilir');
        elseif (is_array($groups)) $add('Uzaktan erişim komut kanalı', 'bad', 'Bağlantı var ama panel kullanıcısı tahta grubunu göremiyor (ACI_MESH_GROUP)');
        else $add('Uzaktan erişim komut kanalı', 'bad', 'MeshCentral\'a bağlanılamadı: ' . mb_substr($r['out'], 0, 120));
    }

    // Disk alanı
    $free = @disk_free_space(ACI_ROOT);
    if ($free !== false) {
        $gb = round($free / 1073741824, 1);
        $add('Disk alanı', $gb < 1 ? 'bad' : ($gb < 3 ? 'warn' : 'ok'), "$gb GB boş");
    }

    // PHP
    $missing = array_filter(['pdo_mysql', 'openssl', 'curl', 'mbstring'], fn($e) => !extension_loaded($e));
    $missing
        ? $add('PHP', 'bad', 'Eksik eklenti: ' . implode(', ', $missing))
        : $add('PHP', 'ok', 'PHP ' . PHP_VERSION);

    // Tahtalar
    $total = (int)db()->query('SELECT COUNT(*) FROM devices')->fetchColumn();
    $seen = (int)db()->query('SELECT COUNT(*) FROM devices WHERE last_seen >= NOW() - INTERVAL 1 DAY')->fetchColumn();
    $add('Tahtalar', $total === 0 ? 'warn' : 'ok', $total === 0 ? 'Henüz kayıtlı tahta yok' : "$total kayıtlı, son 24 saatte $seen bağlandı");

    return $c;
}
