<?php
// Panelden MeshCentral'a komut kanalı (uzaktan açma, uyku, web adresi açma, bildirim).
// MeshCentral'ın kendi komut aracı (meshctrl) sadece tahta grubunda yetkili
// "panel-api" kullanıcısıyla çalıştırılır. Kabuk kullanılmaz (argüman dizisi).
declare(strict_types=1);

const MESHCTRL_JS = '/opt/meshcentral/node_modules/meshcentral/meshctrl.js';
const NODE_BIN = '/opt/node/bin/node';

function meshctl_enabled(): bool {
    return cfg('ACI_MESH_WSS') !== '' && cfg('ACI_MESH_API_USER') !== '' && cfg('ACI_MESH_API_PASS') !== ''
        && is_file(MESHCTRL_JS) && is_file(NODE_BIN);
}

/** Ajanın bildirdiği 96 hane hex kimliği MeshCentral kimliğine çevirir (node//...). */
function mesh_node_ref(string $hex): ?string {
    if (!preg_match('/^[0-9A-Fa-f]{96}$/', $hex)) return null;
    return 'node//' . strtr(base64_encode(hex2bin($hex)), ['+' => '@', '/' => '$']);
}

/**
 * meshctrl çalıştırır. Dönüş: ['ok' => bool, 'out' => string].
 * Sunucu yerel bağlantıda (127.0.0.1) kendinden imzalı sertifika kullandığı için
 * sertifika doğrulaması yalnız yerel adreste kapatılır.
 */
function meshctl(array $args, int $timeout = 30): array {
    if (!meshctl_enabled()) return ['ok' => false, 'out' => 'Uzaktan erişim komut kanalı ayarlanmamış.'];
    $url = cfg('ACI_MESH_WSS');
    $env = ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()];
    $host = parse_url($url, PHP_URL_HOST);
    if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';

    $cmd = array_merge([NODE_BIN, '--no-warnings', MESHCTRL_JS], $args,
        ['--url', $url, '--loginuser', cfg('ACI_MESH_API_USER'), '--loginpass', cfg('ACI_MESH_API_PASS')]);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) return ['ok' => false, 'out' => 'meshctrl başlatılamadı'];
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = microtime(true) + $timeout;
    while (true) {
        $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        if (microtime(true) > $deadline) {
            proc_terminate($proc);
            $out .= "\nzaman aşımı";
            break;
        }
        usleep(50000);
    }
    $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $exit = isset($st) && !$st['running'] ? $st['exitcode'] : $code;
    $out = trim($out);
    $ok = $exit === 0 && !preg_match('/(invalid|denied|not found|error|failed)/i', $out);
    return ['ok' => $ok, 'out' => mb_substr($out, 0, 4000)];
}

/** Güç işlemleri: wake (uzaktan aç), sleep, off, reset. $hexIds: ajanın bildirdiği kimlikler. */
function mesh_power(string $op, array $hexIds): array {
    if (!in_array($op, ['wake', 'sleep', 'off', 'reset'], true)) return ['ok' => false, 'out' => 'geçersiz işlem'];
    $refs = array_values(array_filter(array_map('mesh_node_ref', $hexIds)));
    if (!$refs) return ['ok' => false, 'out' => 'Seçilen tahtaların uzaktan erişim kimliği yok.'];
    return meshctl(['devicepower', '--' . $op, '--id', implode(',', $refs)], 60);
}

/** Tahtalarda bir web adresi açar (ör. ders materyali). */
function mesh_open_url(array $hexIds, string $url): array {
    if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return ['ok' => false, 'out' => 'Geçerli bir http(s) adresi girin.'];
    }
    $results = [];
    $ok = true;
    foreach (array_filter(array_map('mesh_node_ref', $hexIds)) as $ref) {
        $r = meshctl(['deviceopenurl', '--id', $ref, '--openurl', $url]);
        $ok = $ok && $r['ok'];
        $results[] = $r['out'];
    }
    return ['ok' => $ok && $results, 'out' => implode("\n", $results)];
}

/** Tahtalarda köşede bildirim (toast) gösterir. */
function mesh_toast(array $hexIds, string $title, string $msg): array {
    $results = [];
    $ok = true;
    foreach (array_filter(array_map('mesh_node_ref', $hexIds)) as $ref) {
        $r = meshctl(['devicetoast', '--id', $ref, '--title', $title ?: school_name(), '--msg', $msg]);
        $ok = $ok && $r['ok'];
        $results[] = $r['out'];
    }
    return ['ok' => $ok && $results, 'out' => implode("\n", $results)];
}
