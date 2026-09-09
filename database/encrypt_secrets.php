<?php

declare(strict_types=1);

/**
 * Идемпотентное шифрование старых открытых секретов и ротация ключа.
 * Новый ключ задаётся в APP_ENCRYPTION_KEY, старый на время ротации —
 * в APP_PREVIOUS_ENCRYPTION_KEY.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();
require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Core\SecretBox;
use App\Models\Setting;

if (!SecretBox::hasValidCurrentKey()) {
    fwrite(STDERR, "APP_ENCRYPTION_KEY должен содержать 64 hex-символа (32 байта).\n");
    exit(1);
}

$pdo = Database::pdo();
$changed = 0;

$rotateColumn = static function (string $table, string $column, string $context) use ($pdo, &$changed): void {
    $rows = $pdo->query("SELECT id, {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} <> ''")->fetchAll();
    $update = $pdo->prepare("UPDATE {$table} SET {$column} = :value WHERE id = :id");
    foreach ($rows as $row) {
        $stored = (string) $row[$column];
        $plain = SecretBox::decrypt($stored, $context);
        if ($plain === null || $plain === '') {
            continue;
        }
        $encrypted = SecretBox::encrypt($plain, $context);
        if (!hash_equals($stored, $encrypted)) {
            $update->execute([':value' => $encrypted, ':id' => (int) $row['id']]);
            $changed++;
        }
    }
};

try {
    $pdo->beginTransaction();
    $rotateColumn('users', 'totp_secret', 'users.totp_secret');
    $rotateColumn('repo_users', 'totp_secret', 'repo_users.totp_secret');
    $rotateColumn('webhooks', 'secret', 'webhooks.secret');

    $rows = $pdo->query('SELECT `key`, `value` FROM settings')->fetchAll();
    $update = $pdo->prepare('UPDATE settings SET `value` = :value WHERE `key` = :key');
    foreach ($rows as $row) {
        $key = (string) $row['key'];
        if (!Setting::isSecret($key) || (string) $row['value'] === '') {
            continue;
        }
        $stored = (string) $row['value'];
        $plain = SecretBox::decrypt($stored, 'settings.' . $key);
        $encrypted = SecretBox::encrypt((string) $plain, 'settings.' . $key);
        if (!hash_equals($stored, $encrypted)) {
            $update->execute([':value' => $encrypted, ':key' => $key]);
            $changed++;
        }
    }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Шифрование остановлено без частичных изменений: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Готово. Зашифровано/перешифровано значений: {$changed}.\n");
