<?php
// Veritabanı güncellemeleri: migrations/ klasöründeki .sql dosyalarını
// sırayla, daha önce uygulanmamış olanları uygular.
// Kullanım: php tools/migrate.php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') exit(1);
$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(128) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$done = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));

$files = glob(__DIR__ . '/../migrations/*.sql');
sort($files, SORT_STRING);
$n = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (isset($done[$version])) continue;
    echo "Uygulanıyor: $version\n";
    $pdo->exec(file_get_contents($file));
    $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
    $n++;
}
echo $n ? "$n güncelleme uygulandı.\n" : "Veritabanı güncel.\n";
