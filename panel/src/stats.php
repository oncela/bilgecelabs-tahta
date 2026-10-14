<?php
// Genel bakış istatistikleri: son 7 gün kullanım ve işlemler, anlık durum dağılımları.
declare(strict_types=1);
require_once __DIR__ . '/status.php';

function dashboard_stats(): array {
    $days = [];
    for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = ['boards' => 0, 'hours' => 0, 'done' => 0, 'failed' => 0];
    $st = db()->query("SELECT DATE(hour) AS d, COUNT(DISTINCT device_id) AS boards, COUNT(*) AS hours
        FROM device_activity WHERE hour >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(hour)");
    foreach ($st as $r) if (isset($days[$r['d']])) { $days[$r['d']]['boards'] = (int)$r['boards']; $days[$r['d']]['hours'] = (int)$r['hours']; }
    $st = db()->query("SELECT DATE(created_at) AS d,
            SUM(status = 'done') AS done, SUM(status IN ('failed','expired')) AS failed
        FROM commands WHERE created_at >= CURDATE() - INTERVAL 6 DAY GROUP BY DATE(created_at)");
    foreach ($st as $r) if (isset($days[$r['d']])) { $days[$r['d']]['done'] = (int)$r['done']; $days[$r['d']]['failed'] = (int)$r['failed']; }

    $state = ['online' => 0, 'offline' => 0];
    $def = ['ok' => 0, 'warn' => 0, 'bad' => 0, 'unknown' => 0];
    $mesh = ['ok' => 0, 'warn' => 0, 'bad' => 0, 'off' => 0];
    foreach (db()->query('SELECT last_seen, defender, mesh_state FROM devices') as $d) {
        $state[is_online($d['last_seen']) ? 'online' : 'offline']++;
        $def[defender_summary($d['defender'])['state']]++;
        $mesh[mesh_summary($d['mesh_state'])['state']]++;
    }
    $labels = array_map(fn($d) => date('d.m', strtotime($d)), array_keys($days));
    return [
        'labels' => $labels,
        'boards' => array_column(array_values($days), 'boards'),
        'hours' => array_column(array_values($days), 'hours'),
        'commands_done' => array_column(array_values($days), 'done'),
        'commands_failed' => array_column(array_values($days), 'failed'),
        'state' => $state,
        'defender' => $def,
        'mesh' => $mesh,
    ];
}
