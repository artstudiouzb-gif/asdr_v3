<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\DemoSeeder;

$db = (string) (getenv('TEST_DB_DATABASE') ?: '');
if ($db === '') {
    fwrite(STDERR, "TEST_DB_DATABASE не задан.\n");
    exit(2);
}

Database::init([
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('TEST_DB_PORT') ?: '3306',
    'database' => $db,
    'username' => getenv('TEST_DB_USERNAME') ?: 'root',
    'password' => getenv('TEST_DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
]);

$pdo = Database::pdo();
$pdo->beginTransaction();

try {
    $created = DemoSeeder::resetAndRun($pdo);
    $issues = DemoSeeder::verify($pdo);
    if ($issues !== []) {
        throw new RuntimeException('DemoSeeder::verify: ' . implode('; ', $issues));
    }

    $heroTranslations = (int) $pdo->query(
        'SELECT COUNT(*) FROM hero_slide_translations'
    )->fetchColumn();
    if (($created['heroes'] ?? 0) < 1 || $heroTranslations < 1) {
        throw new RuntimeException(
            "Hero demo incomplete: heroes=" . (int) ($created['heroes'] ?? 0)
            . ", translations={$heroTranslations}"
        );
    }

    // Самая важная регрессия текущего релиза: после чистой установки модель
    // должна уметь читать языки Hero без Unknown column cta_url.
    $slideIds = array_map(
        'intval',
        $pdo->query('SELECT id FROM hero_slides ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN) ?: []
    );
    \App\Models\HeroSlideTranslation::availableLangsForIds($slideIds);

    fwrite(STDOUT, "Demo seed smoke OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

exit(0);
