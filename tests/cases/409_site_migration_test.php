<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\SiteMigration;

/*
 * Переезд на другой хостинг (SiteMigration, scripts/site_migrate.php): ключ
 * шифрования сверяется до замены базы, абсолютный адрес заменяется с
 * границей, пакет не затирается страховочной копией той же секунды.
 */

function migration_test_db(): void
{
    $db = getenv('TEST_DB_DATABASE');
    if ($db === false || $db === '') {
        skip_test('TEST_DB_* не заданы');
    }
    if (!Database::isConnected()) {
        Database::init([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: '3306',
            'database' => $db,
            'username' => getenv('TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ]);
    }
}

test('Отпечаток ключа: одинаков для hex и base64 одного ключа и не выдаёт сам ключ', function (): void {
    $bytes = random_bytes(32);
    $hex = bin2hex($bytes);
    $fp = SiteMigration::keyFingerprint($hex);
    assert_same(16, strlen((string) $fp));
    assert_same($fp, SiteMigration::keyFingerprint(base64_encode($bytes)), 'запись ключа не меняет отпечаток');
    assert_true($fp !== SiteMigration::keyFingerprint(bin2hex(random_bytes(32))), 'другой ключ — другой отпечаток');
    assert_false(str_contains($hex, (string) $fp), 'отпечаток не кусок ключа');
    assert_same(null, SiteMigration::keyFingerprint('короткий'), 'негодный ключ отпечатка не имеет');
});

test('Ключ сверяется до замены базы: чужой или отсутствующий ключ — отказ', function (): void {
    assert_contains('не был задан', SiteMigration::assertKeyCompatible(null, 'aaaaaaaaaaaaaaaa'));
    assert_contains('совпадает', SiteMigration::assertKeyCompatible('aaaaaaaaaaaaaaaa', 'aaaaaaaaaaaaaaaa'));
    foreach ([['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb'], ['aaaaaaaaaaaaaaaa', null]] as [$archive, $current]) {
        $thrown = '';
        try {
            SiteMigration::assertKeyCompatible($archive, $current);
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }
        assert_contains('APP_ENCRYPTION_KEY', $thrown, 'сообщение называет, что перенести');
    }
});

test('Адрес заменяется с границей: путь, конец предложения, JSON; чужой хост — нет', function (): void {
    $pairs = SiteMigration::urlPairs('https://asdr.uz/', 'https://new.asdr.uz');
    assert_same(['https://asdr.uz' => 'https://new.asdr.uz', 'https:\/\/asdr.uz' => 'https:\/\/new.asdr.uz'], $pairs);
    $r = static fn (string $t): string => SiteMigration::replaceInString($t, 'https://asdr.uz', 'https://new.asdr.uz');
    assert_same('<a href="https://new.asdr.uz/news">', $r('<a href="https://asdr.uz/news">'));
    assert_same('Сайт: https://new.asdr.uz.', $r('Сайт: https://asdr.uz.'), 'точка в конце предложения — граница');
    assert_same('https://asdr.uz.gov/x', $r('https://asdr.uz.gov/x'), 'другой хост через точку не трогается');
    assert_same('https://asdr.uzbek.uz', $r('https://asdr.uzbek.uz'), 'хост с тем же началом не трогается');
    assert_same('https://asdr.uz:8443/a', $r('https://asdr.uz:8443/a'), 'другой порт — другой адрес');
    $json = SiteMigration::replaceInString('{"url":"https:\/\/asdr.uz\/doc"}', 'https:\/\/asdr.uz', 'https:\/\/new.asdr.uz');
    assert_same('{"url":"https:\/\/new.asdr.uz\/doc"}', $json);
    assert_same([], SiteMigration::urlPairs('https://asdr.uz', 'https://asdr.uz/'), 'тот же адрес — замены нет');

    foreach (['ftp://asdr.uz', '/relative', 'https://asdr.uz/?a=1'] as $bad) {
        $thrown = false;
        try {
            SiteMigration::normalizeUrl($bad);
        } catch (\RuntimeException) {
            $thrown = true;
        }
        assert_true($thrown, $bad . ' — не адрес сайта');
    }
});

test('Замена адреса в базе: подсчёт ничего не пишет, применение меняет только свой хост', function (): void {
    migration_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DROP TABLE IF EXISTS migration_url_probe');
    // meta — текст с JSON внутри, как данные блоков: запись «https:\/\/» там
    // сохраняется. Колонка типа JSON в MySQL хранит значение нормализованным
    // (без «\/»), в MariaDB — как текст; её проверяем только на исчезновение
    // старого адреса.
    $pdo->exec('CREATE TABLE migration_url_probe (id INT PRIMARY KEY, body TEXT, meta LONGTEXT NULL, doc JSON NULL)');
    try {
        $ins = $pdo->prepare('INSERT INTO migration_url_probe (id, body, meta, doc) VALUES (?, ?, ?, ?)');
        $ins->execute([1, 'См. https://old.asdr.uz/news и https://old.asdr.uz.', null, null]);
        $ins->execute([2, 'https://old.asdr.uzbek.uz/x', json_encode(['u' => 'https://old.asdr.uz/doc']), json_encode(['u' => 'https://old.asdr.uz/j'])]);
        $ins->execute([3, 'без ссылок', null, null]);

        $dry = SiteMigration::replaceUrl($pdo, 'https://old.asdr.uz', 'https://asdr.uz', true);
        assert_same(3, $dry['migration_url_probe'] ?? 0, 'текст строки 1, JSON-текст и JSON-колонка строки 2');
        $body = (string) $pdo->query('SELECT body FROM migration_url_probe WHERE id = 1')->fetchColumn();
        assert_contains('old.asdr.uz', $body, 'подсчёт ничего не меняет');

        SiteMigration::replaceUrl($pdo, 'https://old.asdr.uz', 'https://asdr.uz', false);
        $rows = $pdo->query('SELECT id, body, meta, doc FROM migration_url_probe ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        assert_same('См. https://asdr.uz/news и https://asdr.uz.', $rows[0]['body']);
        assert_same('https://old.asdr.uzbek.uz/x', $rows[1]['body'], 'чужой хост остался');
        assert_contains('https:\/\/asdr.uz\/doc', (string) $rows[1]['meta'], 'экранированная запись JSON заменена');
        assert_not_contains('old.asdr.uz', (string) $rows[1]['doc'], 'колонка JSON — в любой нормализации');
        assert_same([], SiteMigration::replaceUrl($pdo, 'https://old.asdr.uz', 'https://asdr.uz', true), 'повтор — ноль');
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS migration_url_probe');
    }
});

test('Пакет не затирается: имя копии не перезаписывается, архив владельца остаётся', function (): void {
    $backup = (string) file_get_contents(APP_ROOT . '/app/Core/Backup.php');
    assert_contains('for ($n = 2; file_exists($zipPath); $n++)', $backup, 'копия той же секунды получает суффикс');
    $restore = (string) file_get_contents(APP_ROOT . '/app/Core/BackupRestore.php');
    assert_contains('if ($ownsArchive) {', $restore, 'архив, положенный владельцем, после установки не удаляется');
    $script = (string) file_get_contents(APP_ROOT . '/scripts/site_migrate.php');
    assert_contains('Cli::assertCli()', $script, 'замена базы — только из консоли, не веб-запросом');
    assert_contains("'--confirm=' . self::CONFIRM_CODE", (string) file_get_contents(APP_ROOT . '/app/Core/SiteMigration.php'));
});

test('Пакет шифруется: дамп закрыт паролем, манифесты открыты, неверный пароль не подходит', function (): void {
    if (!\App\Core\Backup::encryptionSupported()) {
        skip_test('libzip без AES-256');
    }
    $path = sys_get_temp_dir() . '/migration-enc-' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE);
    $zip->addFromString('database.sql', "CREATE TABLE t (id INT);\n");
    $zip->addFromString('manifest.txt', "ASDR CMS backup\n");
    $zip->addFromString(SiteMigration::MANIFEST, json_encode(['kind' => SiteMigration::KIND, 'key_fingerprint' => null]));
    $zip->setEncryptionName('database.sql', \ZipArchive::EM_AES_256, 'correct-horse-battery');
    $zip->close();
    try {
        assert_true(SiteMigration::isEncrypted($path), 'дамп зашифрован');
        assert_same(SiteMigration::KIND, (SiteMigration::readManifest($path) ?? [])['kind'] ?? null, 'манифест читается без пароля');
        assert_true(SiteMigration::passwordMatches($path, 'correct-horse-battery'));
        assert_false(SiteMigration::passwordMatches($path, 'wrong-password-123'), 'чужой пароль не подходит');
    } finally {
        @unlink($path);
    }

    $backup = (string) file_get_contents(APP_ROOT . '/app/Core/Backup.php');
    assert_contains('setEncryptionIndex($index, ZipArchive::EM_AES_256, $password)', $backup, 'Backup::create шифрует записи');
    assert_contains('$zip->setPassword($password)', $backup, 'Backup::restore расшифровывает');

    $thrown = false;
    try {
        SiteMigration::assertPassword('короткий');
    } catch (\RuntimeException) {
        $thrown = true;
    }
    assert_true($thrown, 'короткий пароль отклоняется');
});

test('Имя архива из формы: только голое имя .zip своего каталога', function (): void {
    $dir = sys_get_temp_dir() . '/migration-resolve-' . bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir . '/pack-1.zip');
    try {
        assert_same($dir . '/pack-1.zip', SiteMigration::resolve($dir, 'pack-1.zip'));
        foreach (['../pack-1.zip', 'sub/pack-1.zip', '.pack.zip', 'pack-1.zip.php', 'missing.zip', ''] as $bad) {
            assert_same(null, SiteMigration::resolve($dir, $bad), $bad . ' не принимается');
        }
    } finally {
        @unlink($dir . '/pack-1.zip');
        @rmdir($dir);
    }
});

test('Состояние переезда — файлом: заказ читается, пароль отдаётся воркеру один раз', function (): void {
    $stateFile = SiteMigration::dir() . '/state.json';
    $saved = is_file($stateFile) ? (string) file_get_contents($stateFile) : null;
    try {
        \App\Core\SiteMigrationState::queue(\App\Core\SiteMigrationState::TASK_IMPORT, 'admin', 'pack.zip', ['to_url' => 'https://new.uz']);
        $state = \App\Core\SiteMigrationState::read();
        assert_same('queued', $state['status']);
        assert_same('https://new.uz', $state['options']['to_url']);
        assert_true(\App\Core\SiteMigrationState::isBusy($state));

        \App\Core\SiteMigrationState::storePassword('correct-horse-battery');
        assert_false(str_contains((string) file_get_contents($stateFile), 'correct-horse'), 'пароль не в журнале');
        assert_same('correct-horse-battery', \App\Core\SiteMigrationState::takePassword());
        assert_same(null, \App\Core\SiteMigrationState::takePassword(), 'второй раз пароля нет');

        \App\Core\SiteMigrationState::storePassword('correct-horse-battery');
        \App\Core\SiteMigrationState::finish(\App\Core\SiteMigrationState::STATUS_FAILED, 'проба');
        assert_same(null, \App\Core\SiteMigrationState::takePassword(), 'окончание задачи стирает пароль');
        assert_false(\App\Core\SiteMigrationState::isBusy());
    } finally {
        \App\Core\SiteMigrationState::clearPassword();
        $saved === null ? @unlink($stateFile) : file_put_contents($stateFile, $saved);
    }
});

test('Панель переезда только заказывает: замену делает воркер из консоли', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/MigrationController.php');
    foreach (['SiteMigration::import(', 'SiteMigration::export(', 'restoreLocal(', 'Backup::create('] as $call) {
        assert_not_contains($call, $controller, $call . ' — не в веб-запросе');
    }
    preg_match_all('/public function (\w+)\(\): void\s*\{\s*(\$this->guardQueue\(\)|Auth::requireSuperAdmin\(\))/', $controller, $m);
    assert_same(['index', 'export', 'import', 'download', 'delete', 'reset'], $m[1], 'каждое действие — только супер-админу');
    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/migration_worker.php');
    assert_contains('Cli::assertCli()', $worker);
    assert_contains('/storage/migration/', (string) file_get_contents(APP_ROOT . '/.gitignore'), 'пакеты не попадают в git');
    assert_contains('/storage/migration/', (string) file_get_contents(APP_ROOT . '/.github/workflows/deploy.yml'), 'и в ветку deploy');
});
