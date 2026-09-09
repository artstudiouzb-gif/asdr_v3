<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\DatabaseDoctor;
use App\Core\MigrationRunner;

test('MigrationRunner: status() и pendingCount() возвращают корректные структуры', function (): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        skip_test('sqlite driver is not installed');
    }
    $tempDir = sys_get_temp_dir() . '/asdr_test_migrations_' . bin2hex(random_bytes(6));
    mkdir($tempDir, 0777, true);

    file_put_contents($tempDir . '/2026_01_01_init.sql', 'CREATE TABLE IF NOT EXISTS test_tbl (id INT);');
    file_put_contents($tempDir . '/2026_01_02_feature.sql', 'ALTER TABLE test_tbl ADD COLUMN name VARCHAR(50);');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $status = MigrationRunner::status($pdo, $tempDir);
    assert_same(2, $status['total']);
    assert_same(2, $status['pending_count']);
    assert_same(0, $status['applied_count']);
    assert_same(2, count($status['pending']));
    assert_same(2, MigrationRunner::pendingCount($pdo, $tempDir));

    @unlink($tempDir . '/2026_01_01_init.sql');
    @unlink($tempDir . '/2026_01_02_feature.sql');
    @rmdir($tempDir);
});

test('DatabaseDoctor: diagnose() возвращает структурированный отчёт о здоровье БД', function (): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        skip_test('sqlite driver is not installed');
    }
    $pdo = new PDO('sqlite::memory:');
    $report = DatabaseDoctor::diagnose($pdo, APP_ROOT . '/database/schema.sql', APP_ROOT . '/database/migrations');

    assert_true(is_array($report));
    assert_true(array_key_exists('is_healthy', $report));
    assert_true(array_key_exists('errors_count', $report));
    assert_true(array_key_exists('warnings_count', $report));
    assert_true(array_key_exists('checks', $report));
    assert_true(is_array($report['checks']));
});

test('Маршруты /admin/database зарегистрированы в системе', function (): void {
    $index = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/database'", $index);
    assert_contains("'/admin/database/migrate'", $index);
    assert_contains("'/admin/database/optimize'", $index);
});

test('В панели управления есть ссылки на управление базой данных', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    assert_contains("'/admin/database'", $header);
    assert_contains('База данных', $header);

    $settings = (string) file_get_contents(APP_ROOT . '/app/Views/admin/settings/index.php');
    assert_contains('/admin/database', $settings);
});
