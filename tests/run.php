<?php

declare(strict_types=1);

/*
 * Нативный тест-раннер ArtStudio CMS (без Composer/PHPUnit — по требованию
 * «никаких сторонних библиотек/Composer»).
 *
 *   php tests/run.php
 *
 * Часть тестов требует БД (миграции, ACL на реальных данных). Они выполняются
 * только если задан доступ к тестовой базе через переменные окружения:
 *   TEST_DB_DATABASE, TEST_DB_USERNAME, TEST_DB_PASSWORD, TEST_DB_HOST.
 * Иначе такие тесты помечаются как пропущенные, а unit-тесты идут всегда.
 */

if (PHP_SAPI !== 'cli') {
    exit('Только из командной строки.');
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

/*
 * Страховка от «зелёного» прогона, который на самом деле не закончился.
 *
 * Тест вызывает продуктовый код, а тот местами завершает процесс (exit/die —
 * так работают редиректы, 403 от WafGuard, отдача файлов). Такой exit обрывает
 * весь набор: следующие тесты не выполняются, итог не печатается, а процесс
 * завершается кодом 0 — CI показывает успех, хотя прогон оборвался и уже
 * накопленные падения потеряны.
 *
 * Флаг снимается только штатным завершением run_tests(). Если обработчик
 * завершения видит его поднятым, прогон считается прерванным и код возврата
 * принудительно становится ненулевым.
 */
$GLOBALS['__suite_incomplete'] = true;
register_shutdown_function(static function (): void {
    if (empty($GLOBALS['__suite_incomplete'])) {
        return;
    }

    $fatal = error_get_last();
    $reason = $fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ? sprintf('фатальная ошибка: %s (%s:%d)', $fatal['message'], $fatal['file'], $fatal['line'])
        : 'тест завершил процесс через exit/die';

    fwrite(STDERR, "\n\033[31mПрогон оборван до итога — {$reason}.\033[0m\n");
    fwrite(STDERR, "Последний начатый тест: " . TestRunner::currentTestName() . "\n");

    exit(1);
});

$cases = glob(__DIR__ . '/cases/*.php') ?: [];
sort($cases, SORT_STRING);
foreach ($cases as $case) {
    fwrite(STDOUT, "\n\033[1m" . basename($case) . "\033[0m\n");
    require $case;
    // Каждый файл добавляет свои test(...) в очередь; выполняем после сбора.
}

$exitCode = run_tests();
$GLOBALS['__suite_incomplete'] = false;

exit($exitCode);
