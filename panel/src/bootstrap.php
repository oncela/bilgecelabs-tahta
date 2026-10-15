<?php
// BilgeceLabs Tahta paneli: ortak ayarlar ve yardımcılar.
declare(strict_types=1);

const ACI_ONLINE_SECONDS = 35;     // bu süre içinde bağlanan tahta "çevrimiçi"
const ACI_POLL_INTERVAL = 10;      // ajanın kaç saniyede bir bağlanacağı
const ACI_COMMAND_TTL_MIN = 15;    // bu süre içinde alınmayan komut iptal olur
const ACI_ACTIVITY_DAYS = 180;    // kullanım kaydı saklama süresi (KVKK)
const ACI_ROOT = __DIR__ . '/..';
const ACI_PRODUCT = 'BilgeceLabs Tahta';

/** Panelde müşteri (okul) adı olarak görünür. */
function school_name(): string {
    return cfg('ACI_SCHOOL_NAME', 'Acı Okulları');
}

date_default_timezone_set('Europe/Istanbul');

function env_load(): array {
    static $env = null;
    if ($env !== null) return $env;
    $env = [];
    $file = ACI_ROOT . '/.env';
    if (is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v);
        }
    }
    return $env;
}

function cfg(string $key, string $default = ''): string {
    return getenv($key) ?: (env_load()[$key] ?? $default);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(cfg('ACI_DB_DSN'), cfg('ACI_DB_USER'), cfg('ACI_DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4, time_zone = '+03:00'");
    return $pdo;
}

function setting(string $k, ?string $default = null): ?string {
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(string $k, string $v): void {
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$k, $v]);
}

/** Ortak alan dosyalarının saklandığı klasör (NAS'a taşınabilir: ACI_SHARED_DIR). */
/** Sunucudaki (dağıtılacak) ajan sürümü. */
function agent_version(): string {
    return trim((string)@file_get_contents(ACI_ROOT . '/storage/aci-ajan.version')) ?: '';
}

/** a > b ise 1, a < b ise -1, eşitse 0 (semver benzeri, sayısal parçalar). */
function version_cmp(string $a, string $b): int {
    $pa = array_map('intval', explode('.', $a));
    $pb = array_map('intval', explode('.', $b));
    for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
        $x = $pa[$i] ?? 0; $y = $pb[$i] ?? 0;
        if ($x !== $y) return $x <=> $y;
    }
    return 0;
}

function shared_dir(): string {
    $d = cfg('ACI_SHARED_DIR');
    return $d !== '' ? rtrim($d, '/') : ACI_ROOT . '/storage/shared';
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function client_ip(): string {
    // Cloudflare Tunnel arkasında gerçek IP bu başlıkta gelir.
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_in(): array {
    $raw = file_get_contents('php://input', false, null, 0, 4 << 20);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function audit(string $action, string $detail = ''): void {
    db()->prepare('INSERT INTO audit_log (admin_id, action, detail, ip) VALUES (?, ?, ?, ?)')
        ->execute([$_SESSION['admin_id'] ?? null, $action, $detail, client_ip()]);
}

// --- Oturum ve yetki (sadece panel sayfaları için) ---

function session_begin(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('aci_panel');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => cfg('ACI_COOKIE_SECURE', '1') === '1',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    // 8 saat işlem yapılmazsa oturumu kapat.
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 8 * 3600) {
        $_SESSION = [];
    }
    $_SESSION['seen'] = time();
}

function require_login(): void {
    session_begin();
    if (empty($_SESSION['admin_id'])) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_out(['error' => 'Oturum kapandı, yeniden giriş yapın.'], 401);
        }
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string {
    session_begin();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_check(): void {
    $sent = $_SERVER['HTTP_X_CSRF'] ?? $_POST['csrf'] ?? '';
    if (!hash_equals(csrf_token(), (string)$sent)) {
        json_out(['error' => 'Güvenlik doğrulaması başarısız. Sayfayı yenileyin.'], 403);
    }
}

// --- Tahta yardımcıları ---

/** Ajanın Bearer token'ından tahta kimliğini bulur; geçersizse 401 döner. */
function device_from_token(): int {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([0-9a-f]{64})$/', $auth, $m)) json_out(['error' => 'token yok'], 401);
    $st = db()->prepare('SELECT id FROM devices WHERE token_hash = ?');
    $st->execute([hash('sha256', $m[1])]);
    $id = (int)$st->fetchColumn();
    if (!$id) json_out(['error' => 'token geçersiz'], 401);
    return $id;
}

/** Cihaz türü etiketleri (panelde görünen). */
/** Lokasyonlar (şubeler), sıralı. */
function locations_all(): array {
    return db()->query('SELECT id, name FROM locations ORDER BY sort, name')->fetchAll();
}

function device_types(): array {
    return ['board' => 'Akıllı tahta', 'classroom' => 'Sınıf bilgisayarı', 'office' => 'İdare', 'desk' => 'Danışma', 'other' => 'Diğer'];
}

function device_label(array $d): string {
    return $d['name'] ?: $d['hostname'] ?: ('Tahta #' . $d['id']);
}

function is_online(?string $lastSeen): bool {
    return $lastSeen !== null && time() - strtotime($lastSeen) <= ACI_ONLINE_SECONDS;
}

/** Geçmiş kayıtlarının (audit_log.action) okunur Türkçe adı. */
function action_label(string $action): string {
    static $names = [
        'login' => 'Giriş', 'logout' => 'Çıkış', 'login_failed' => 'Hatalı giriş',
        'command' => 'Komut gönderildi', 'device_enroll' => 'Yeni cihaz kaydoldu', 'device_update' => 'Cihaz bilgisi düzenlendi',
        'device_delete' => 'Cihaz silindi', 'netscan' => 'Ağ taraması', 'enroll_key' => 'Kayıt anahtarı yenilendi',
        'download' => 'Kurulum dosyası indirildi', 'password' => 'Şifre değiştirildi',
        'mesh_wake' => 'Uzaktan açma', 'mesh_sleep' => 'Uykuya alma', 'mesh_off' => 'Uzaktan kapatma', 'mesh_reset' => 'Uzaktan yeniden başlatma',
        'mesh_open_url' => 'Web adresi açıldı', 'mesh_toast' => 'Bildirim gönderildi',
        'shared_upload' => 'Ortak alana dosya yüklendi', 'shared_send' => 'Dosya gönderildi', 'shared_delete' => 'Ortak alandan dosya silindi',
        'catalog_save' => 'Yazılım listesi güncellendi', 'catalog_delete' => 'Yazılım listeden çıkarıldı', 'catalog_install' => 'Yazılım kurulumu başlatıldı',
        'selfservice_install' => 'Kullanıcı yazılım kurdu', 'selfservice' => 'Self servis ayarı',
        'location_save' => 'Lokasyon kaydedildi', 'location_delete' => 'Lokasyon silindi', 'assign_location' => 'Cihazlar lokasyona atandı',
        'alert_resolve' => 'Uyarı çözüldü', 'ticket_update' => 'Destek talebi yanıtlandı',
        'auto_update' => 'Otomatik güncelleme ayarı', 'notify_settings' => 'Bildirim ayarı',
        'remote_desktop' => 'Ekrana bağlanıldı', 'remote_files' => 'Dosyalar açıldı', 'remote_terminal' => 'Komut satırı açıldı',
    ];
    return $names[$action] ?? (str_starts_with($action, 'mesh_') ? 'Uzaktan işlem' : $action);
}

function new_batch_id(): string {
    return bin2hex(random_bytes(8));
}

/** Hazır betikli komutlar: tür -> src/scripts altındaki PowerShell dosyası. */
function preset_script(string $type): ?string {
    $map = ['wol_prepare' => 'wol_prepare.ps1'];
    if (!isset($map[$type])) return null;
    $f = __DIR__ . '/scripts/' . $map[$type];
    return is_file($f) ? (string)file_get_contents($f) : null;
}

/** Komut türleri: panelde görünen ad ve izin verilen argümanlar. */
function command_types(): array {
    return [
        'message'         => ['Mesaj gönder', ['text', 'title', 'timeout']],
        'shutdown'        => ['Kapat', ['delay', 'text']],
        'restart'         => ['Yeniden başlat', ['delay', 'text']],
        'cancel_shutdown' => ['Kapatmayı iptal et', []],
        'powershell'      => ['PowerShell çalıştır', ['script', 'timeout']],
        'defender_scan'   => ['Virüs taraması', []],
        'defender_update' => ['Virüs imzası güncelle', []],
        'netscan'         => ['Ağ taraması', []],
        'run_app'         => ['Uygulama/adres aç', ['path', 'args']],
        'uninstall'       => ['Tahtadan kaldır', []],
        // Panelde tanımlı hazır betikler: tahtaya PowerShell olarak gider (bkz. preset_script)
        'wol_prepare'     => ['Uzaktan açmaya hazırla', []],
    ];
}
