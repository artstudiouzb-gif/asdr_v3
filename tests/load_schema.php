<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/MigrationRunner.php';

$dbName = $argv[1] ?? 'asdr_test';
$host = getenv('TEST_DB_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
$port = getenv('TEST_DB_PORT') ?: (getenv('DB_PORT') ?: '3306');
$user = getenv('TEST_DB_USERNAME') ?: (getenv('DB_USERNAME') ?: 'root');
$pass = getenv('TEST_DB_PASSWORD') ?: (getenv('DB_PASSWORD') ?: '');

$dsnRoot = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
$pdo = new PDO($dsnRoot, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    \Pdo\Mysql::ATTR_MULTI_STATEMENTS => true,
]);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$dbName}`");

$schemaPath = __DIR__ . '/../database/schema.sql';
if (!file_exists($schemaPath)) {
    throw new RuntimeException("Schema file not found at {$schemaPath}");
}

$sql = file_get_contents($schemaPath);
$stmt = $pdo->prepare($sql);
$stmt->execute();
while ($stmt->nextRowset()) {
    // flush remaining statement sets
}

$applied = \App\Core\MigrationRunner::applyPending(
    $pdo,
    __DIR__ . '/../database/migrations'
);

echo "Schema successfully loaded into {$dbName}; pending migrations applied: " . count($applied) . "!\n";
