<?php
// İzleme için sağlık özeti. Ayrıntı vermez (sadece genel durum);
// ayrıntılar giriş yapılmış Ayarlar sayfasındadır.
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/health.php';

try {
    $checks = health_checks();
    $bad = array_values(array_filter($checks, fn($c) => $c['level'] === 'bad'));
    json_out(['ok' => !$bad, 'checks' => count($checks), 'problems' => count($bad)], $bad ? 503 : 200);
} catch (Throwable $e) {
    json_out(['ok' => false], 503);
}
