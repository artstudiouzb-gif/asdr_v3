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
