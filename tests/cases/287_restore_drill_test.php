<?php

declare(strict_types=1);

use App\Core\RestoreDrill;

test('Репетиция: отказывается работать, пока не доказала безопасность', function (): void {
    $live = (string) \App\Core\Config::get('db.database', '');

    if ($live !== '') {
        // Совпадение имён означало бы, что репетиция затрёт боевые данные дампом.
        $result = RestoreDrill::run($live);
        assert_false($result['ok']);
        assert_contains('отличаться от рабочей', implode(' ', $result['messages']));
        assert_same(0, $result['tables']);
    } else {
        // Имя рабочей базы неизвестно — доказать, что мы не на ней, нечем.
        $result = RestoreDrill::run('artstudio_drill_probe');
        assert_false($result['ok']);
        assert_contains('Имя рабочей базы неизвестно', implode(' ', $result['messages']));
    }
});

test('Репетиция: имя базы проверяется, а не подставляется в SQL как есть', function (): void {
    // Имя уходит в CREATE DATABASE и DROP DATABASE, где параметр не подставить,
    // поэтому набор символов ограничен жёстко.
    foreach (['drill; DROP DATABASE live', 'drill`', 'дрель', 'drill-1'] as $bad) {
        $result = RestoreDrill::run($bad);
        assert_false($result['ok'], 'принято недопустимое имя: ' . $bad);
        assert_contains('Недопустимое имя', implode(' ', $result['messages']));
    }
});

test('Репетиция: отметка лежит там, где её ищет release_check', function (): void {
    // Разъедутся пути — release_check снова начнёт писать «ни разу не
    // проверялось» при работающей репетиции.
    assert_contains('.last_restore_check', RestoreDrill::markerPath());

    $releaseCheck = (string) file_get_contents(APP_ROOT . '/scripts/release_check.php');
    assert_contains("/storage/backups/.last_restore_check", $releaseCheck);

    $legacy = (string) file_get_contents(APP_ROOT . '/database/restore.php');
    assert_contains(".last_restore_check", $legacy);
});

test('Репетиция: воркер существует, не трогает боевые данные и сообщает о провале', function (): void {
    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/restore_drill.php');

    assert_contains('Cli::assertCli()', $worker);
    assert_contains('RestoreDrill::run(', $worker);
    // Неудачная репетиция означает, что бэкапов, по сути, нет: узнать об этом
    // в день аварии слишком поздно.
    assert_contains('FormNotifier::broadcast', $worker);
    assert_contains('exit(1)', $worker);
    // Отметка ставится только после успеха, иначе release_check будет считать
    // свежей репетицию, которая провалилась.
    assert_contains("if (\$result['ok']) {", $worker);

    $drill = (string) file_get_contents(APP_ROOT . '/app/Core/RestoreDrill.php');
    // Разворачивается во временный каталог, а не в боевые загрузки.
    assert_contains('sys_get_temp_dir()', $drill);
    // Контрольная сумма проверяется до восстановления: битый архив — самый
    // частый и самый тихий отказ бэкапа.
    assert_contains('Backup::verify(', $drill);
});

test('Репетиция: отсутствующий архив — честная неудача, а не тишина', function (): void {
    $result = RestoreDrill::run('artstudio_drill_probe_' . bin2hex(random_bytes(3)), '/tmp/нет-такого-архива.zip');

    assert_false($result['ok']);
    assert_true($result['messages'] !== []);
});
