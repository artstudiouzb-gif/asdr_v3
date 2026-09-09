<?php

declare(strict_types=1);

/*
 * Актуальный двуязычный демо-комплект государственного агентства: главная,
 * проекты, медиа, новости, документы, вакансии, тендеры, мероприятия,
 * руководство, формы, типовые страницы и меню.
 *
 *   php database/seed_demo.php
 *   php database/seed_demo.php --reset
 *
 * Без флага существующие данные не изменяются. --reset полностью заменяет
 * контент эталонным комплектом и запускает проверку целостности.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

try {
    // CLI должен подключаться самостоятельно: storage/installed.lock может
    // отсутствовать в локальной копии репозитория, хотя config.php и рабочая
    // БД уже настроены. В таком случае bootstrap намеренно не открывает БД.
    if (!\App\Core\Database::isConnected()) {
        $dbConfig = (array) \App\Core\Config::get('db', []);
        if (($dbConfig['database'] ?? '') === '') {
            throw new \RuntimeException(
                'Параметры базы данных не найдены. Проверьте config/config.php.'
            );
        }
        \App\Core\Database::init($dbConfig);
    }

    $reset = in_array('--reset', $argv, true);
    if ($reset) {
        $backupPath = \App\Core\Backup::create();
        fwrite(STDOUT, 'Резервная копия создана: ' . basename($backupPath) . "\n");
    }
    $created = $reset
        ? \App\Core\DemoSeeder::resetAndRun(\App\Core\Database::pdo())
        : \App\Core\DemoSeeder::run(\App\Core\Database::pdo());

    fwrite(STDOUT, $reset
        ? "Старый контент удалён, новый демо-комплект создан и проверен:\n"
        : "Демо-контент добавлен:\n");
    foreach ($created as $section => $n) {
        fwrite(STDOUT, sprintf("  %-12s +%d\n", $section, $n));
    }
    fwrite(STDOUT, "Готово. Записи можно редактировать/удалять в админке.\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка демо-наполнения: ' . $e->getMessage() . "\n");
    exit(1);
}
