<?php

declare(strict_types=1);

test('Composer подключён только для инструментов разработки', function () {
    $path = APP_ROOT . '/composer.json';
    $data = json_decode((string) file_get_contents($path), true);
    assert_true(is_array($data), 'composer.json содержит валидный JSON');
    // В `require` допустима одна запись — платформенное требование `php`:
    // это не зависимость (ставить нечего), а объявленная планка версии, та же,
    // что в EnvironmentCheck и release_check. Всё остальное — пакеты, а их у
    // production быть не должно: сайт работает на голом PHP.
    $require = $data['require'] ?? null;
    assert_true(is_array($require), 'секция require описана');
    assert_same([], array_diff(array_keys($require), ['php']), 'production-зависимости отсутствуют');
    assert_same('>=8.4', $require['php'] ?? '', 'планка версии PHP объявлена и совпадает с EnvironmentCheck');
    // Проверяем сам инвариант («версия закреплена»), а не конкретное число:
    // штатное обновление PHPStan не должно ронять набор, а вот диапазон
    // (^, ~, *, >=) сделает сборку невоспроизводимой и должен быть отклонён.
    $phpstan = $data['require-dev']['phpstan/phpstan'] ?? null;
    assert_true(
        is_string($phpstan) && preg_match('/^\d+\.\d+(\.\d+)?$/', $phpstan) === 1,
        'версия PHPStan закреплена точным номером, а не диапазоном'
    );
    assert_false(isset($data['autoload']), 'production-автозагрузка Composer не включена');

    $gitignore = (string) file_get_contents(APP_ROOT . '/.gitignore');
    assert_contains('/vendor/', $gitignore);
});

test('CI устанавливает dev-инструменты через Composer', function () {
    $workflow = (string) file_get_contents(APP_ROOT . '/.github/workflows/ci.yml');
    assert_contains('composer install --no-interaction --prefer-dist --no-progress', $workflow);
    assert_contains('run: composer audit --no-interaction', $workflow);
    assert_contains('run: composer analyse', $workflow);
    assert_not_contains('composer analyse || true', $workflow);
    assert_not_contains('tools: phpstan', $workflow);
});
