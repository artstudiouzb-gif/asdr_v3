<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\NotFoundLog;

/** Таблица 404-трекера (идемпотентно). */
function ensure_notfound_table(): void
{
    ensure_test_db();
    Database::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_not_found_log.sql'));
}

test('404-трекер: агрегирует хиты, хранит внешний referer, фильтрует шум (БД)', function () {
    ensure_notfound_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM not_found_log');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'site.uz';

    // Обычный путь с внешним referer.
    $_SERVER['REQUEST_URI'] = '/staraya-stranica?utm=1';
    $_SERVER['HTTP_REFERER'] = 'https://google.com/search';
    NotFoundLog::record();
    NotFoundLog::record();

    // Внутренний referer не сохраняется как внешний.
    $_SERVER['REQUEST_URI'] = '/drugoy-put';
    $_SERVER['HTTP_REFERER'] = 'https://site.uz/nekaya-stranica';
    NotFoundLog::record();

    // Шум сканеров и статика не записываются.
    foreach (['/wp-admin/setup.php', '/.env', '/style.css', '/img/logo.png', '/admin/x'] as $noise) {
        $_SERVER['REQUEST_URI'] = $noise;
        $_SERVER['HTTP_REFERER'] = '';
        NotFoundLog::record();
    }

    // POST не записывается.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/post-put';
    NotFoundLog::record();
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $rows = NotFoundLog::top();
    assert_same(2, count($rows), 'записаны только два осмысленных пути');
    assert_same('/staraya-stranica', (string) $rows[0]['path'], 'внешний referer — выше в списке');
    assert_same(2, (int) $rows[0]['hits'], 'хиты агрегируются');
    assert_true(str_contains((string) $rows[0]['last_referer'], 'google.com'));
    assert_true($rows[1]['last_referer'] === null, 'свой домен не считается внешним referer');

    // Удаление по пути (после создания редиректа).
    NotFoundLog::deleteByPath('/staraya-stranica');
    assert_same(1, count(NotFoundLog::top()));

    // Чистка старых.
    $pdo->exec("UPDATE not_found_log SET last_hit_at = DATE_SUB(NOW(), INTERVAL 100 DAY)");
    assert_same(1, NotFoundLog::purgeOlderThan(90));
    assert_same(0, count(NotFoundLog::top()));

    unset($_SERVER['HTTP_REFERER']);
    $pdo->exec('DELETE FROM not_found_log');
});

test('Open Data: индекс и датасет news отдают валидный JSON, кэш пишется (БД)', function () {
    ensure_test_db();
    $cacheDir = APP_ROOT . '/storage/cache/opendata';
    array_map('unlink', glob($cacheDir . '/*.json') ?: []);

    $controller = new \App\Controllers\Site\OpenDataController();

    ob_start();
    $controller->index();
    $index = json_decode((string) ob_get_clean(), true);
    assert_true(is_array($index), 'индекс — валидный JSON');
    $ids = array_column($index['datasets'], 'id');
    assert_true(in_array('news', $ids, true), 'датасет news заявлен');
    assert_true(in_array('documenty', $ids, true), 'публичные типы контента заявлены');

    ob_start();
    $controller->dataset(['dataset' => 'news.json']);
    $news = json_decode((string) ob_get_clean(), true);
    assert_same('news', (string) $news['dataset']);
    assert_true(isset($news['count'], $news['items'], $news['generated_at']));
    assert_true(is_file($cacheDir . '/news.json'), 'жёсткий кэш на диске создан');

    // Неизвестный датасет — JSON с ошибкой.
    ob_start();
    $controller->dataset(['dataset' => 'nesuschestvuyuschiy.json']);
    $err = json_decode((string) ob_get_clean(), true);
    assert_true(isset($err['error']));

    array_map('unlink', glob($cacheDir . '/*.json') ?: []);
});

test('Mailer::lastError содержит точную причину сбоя SMTP', function () {
    \App\Core\Config::merge(['mail' => [
        'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1,
        'encryption' => '', 'username' => '', 'password' => '',
        'from_email' => 'noreply@test', 'from_name' => 'Test',
    ]]);

    $mailer = new \App\Core\Mailer();
    assert_true($mailer->lastError() === null, 'до отправки ошибки нет');
    assert_false($mailer->send('user@example.com', 'S', 'B'));
    assert_true($mailer->lastError() !== null, 'ошибка сохранена');
    assert_true(str_contains((string) $mailer->lastError(), 'SMTP'), 'текст содержит причину');
});

test('Mailer отклоняет адрес с CRLF (защита от инъекции SMTP-команд)', function () {
    \App\Core\Config::merge(['mail' => [
        'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1,
        'encryption' => '', 'username' => '', 'password' => '',
        'from_email' => 'noreply@test', 'from_name' => 'Test',
    ]]);

    $mailer = new \App\Core\Mailer();
    // Попытка вписать лишний заголовок/команду через перевод строки в адресе.
    $injected = "user@example.com\r\nRCPT TO:<victim@example.com>";
    assert_false($mailer->send($injected, 'S', 'B'), 'адрес с CRLF отклоняется');
    assert_true(
        str_contains((string) $mailer->lastError(), 'Недопустимые символы'),
        'причина — управляющие символы, а не сбой соединения'
    );

    // То же для отображаемого имени получателя.
    $mailer2 = new \App\Core\Mailer();
    assert_false(
        $mailer2->send('user@example.com', 'S', 'B', "Имя\r\nBcc: evil@example.com"),
        'имя с CRLF отклоняется'
    );
});
