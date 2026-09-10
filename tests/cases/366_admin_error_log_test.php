<?php

declare(strict_types=1);

use App\Core\LogReader;

/*
 * Журнал ошибок в панели.
 *
 * Журнал был всегда, но лежал файлом на shared-хостинге, куда владелец не
 * заходит: за неделю он дважды скачивал `error.log` и отдавал его инженеру,
 * чтобы узнать, что происходит на его же сайте. Раздел отвечает на это сам.
 *
 * Три решения стерегутся здесь, потому что каждое легко потерять при правке.
 */

test('Читается хвост файла, а не файл целиком', function (): void {
    // Журнал растёт без предела, и чтение целиком кладёт панель по памяти
    // ровно тогда, когда журнал понадобился, — в аварии.
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/LogReader.php');
    assert_contains('SEEK_END', $source, 'хвост берётся смещением от конца');
    assert_contains('fgets(', $source, 'строки читаются потоком, а не массивом целиком');
    assert_not_contains('file_get_contents($file)', $source, 'файл журнала не читается целиком');
    // Проверять подстроку «file($file» нельзя: она встречается внутри
    // is_file($file) — сторож падал бы на исправном коде.
    assert_false(
        preg_match('/[^_a-z]file\(\s*\$file/i', $source) === 1,
        'массивом файл журнала не читается'
    );
});

test('Одинаковыми считаются только совпадающие дословно', function (): void {
    $dir = LogReader::dir();
    @mkdir($dir, 0775, true);
    $file = $dir . '/app.log';
    $backup = is_file($file) ? (string) file_get_contents($file) : null;

    file_put_contents($file, implode("\n", [
        '[2026-09-10 01:00:00] INFO: Сбой в строке 12',
        '[2026-09-10 01:00:01] INFO: Сбой в строке 12',
        '[2026-09-10 01:00:02] INFO: Сбой в строке 48',
        '[2026-09-10 01:00:03] WARNING: Сбой в строке 12',
        'продолжение стека без нашего формата',
    ]) . "\n");

    $data = LogReader::read('app');

    // Соблазн свести «строку 12» и «строку 48» велик, но это тихое слипание:
    // две разные поломки показались бы одной. Лишний ряд дешевле спрятанной
    // ошибки.
    assert_same(3, count($data['groups']), 'разные сообщения и разные уровни не сливаются');
    assert_same(4, $data['total'], 'разобраны все записи нашего формата');
    assert_same(1, $data['unparsed'], 'чужая строка посчитана отдельно, а не выдана за запись');

    // Порядок — по числу повторов: чинить нужно то, что повторяется.
    assert_same(2, $data['groups'][0]['count'], 'самая частая запись идёт первой');
    assert_contains('строке 12', $data['groups'][0]['message']);

    if ($backup === null) {
        @unlink($file);
    } else {
        file_put_contents($file, $backup);
    }
});

test('Счёт за сутки идёт по записям, а не по группам', function (): void {
    // У группы своя дата только у последнего повтора. Зачесть всю группу в
    // сутки значило бы показать на витрине состояния число больше настоящего
    // — то есть соврать в цифре, ради которой раздел и открывают.
    $dir = LogReader::dir();
    @mkdir($dir, 0775, true);
    $file = $dir . '/app.log';
    $backup = is_file($file) ? (string) file_get_contents($file) : null;

    $fresh = date('Y-m-d H:i:s', time() - 60);
    file_put_contents($file, implode("\n", [
        '[2019-05-05 10:00:00] INFO: Одно и то же',
        '[2019-05-06 10:00:00] INFO: Одно и то же',
        '[' . $fresh . '] INFO: Одно и то же',
    ]) . "\n");

    assert_same(1, LogReader::countSince('app'), 'за сутки засчитана одна запись из трёх');

    if ($backup === null) {
        @unlink($file);
    } else {
        file_put_contents($file, $backup);
    }
});

test('Имя журнала не уходит в путь как есть', function (): void {
    // Значение приходит из адреса, а путь собирается конкатенацией: без
    // списка это чтение произвольного файла.
    assert_same('', LogReader::path('../../config/config'), 'чужое имя отвергается');
    assert_same('', LogReader::path('error/../../.env'), 'обход каталога отвергается');
    assert_true(str_ends_with(LogReader::path('error'), '/error.log'), 'известный канал разрешается');
});

test('Раздел объявлен целиком и доступен только супер-админу', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/logs'", $routes, 'маршрут раздела');
    assert_contains("'/admin/logs/clear'", $routes, 'маршрут очистки');

    $nav = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    assert_contains("'logs' => ['/admin/logs'", $nav, 'пункт меню');

    // Неизвестный ключ Icon::render отдаёт пустой строкой, и пункт молча
    // остаётся без иконки (тест 248).
    $ui = (string) file_get_contents(APP_ROOT . '/app/Core/AdminUi.php');
    assert_contains("'logs' => 'alert-triangle'", $ui, 'иконка раздела');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/LogController.php');
    assert_contains('requireSuperAdmin', $controller, 'записи журнала не для редактора');
    assert_contains('Csrf::verifyRequest', $controller, 'очистка — действие, а не чтение');

    // Витрина состояния обязана называть число: журнал, о котором не знают,
    // остаётся таким же невидимым, каким был файл на диске.
    $health = (string) file_get_contents(APP_ROOT . '/app/Core/SystemHealth.php');
    assert_contains('errors_24h', $health, 'ошибки за сутки показаны в состоянии системы');
});
