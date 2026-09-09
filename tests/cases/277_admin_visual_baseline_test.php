<?php

declare(strict_types=1);

// Эталон вычисленных стилей админки. CSS панели собран слоями (базовый файл,
// слой переопределений поверх него и патч-файлы, которые подключает
// загрузчик), поэтому правка в одном слое молча меняет вид в другом. Тест
// стережёт не сам вид — его сверяет Playwright, — а то, что механизм на
// месте: фикстура, спека, эталонные файлы и шаг CI, который их создаёт.

test('Визуальные регрессы админки: фикстура, спека и эталон на месте', function (): void {
    $spec = (string) file_get_contents(APP_ROOT . '/tests/browser/admin-visual.spec.js');
    $seed = (string) file_get_contents(APP_ROOT . '/tests/browser/seed_admin_visual.php');
    $ci = (string) file_get_contents(APP_ROOT . '/.github/workflows/ci.yml');

    assert_contains('@visual', $spec, 'спека попадает в npm run test:visual');
    assert_contains("mode: 'serial'", $spec, 'вход один на файл: шаг TOTP одноразовый');
    assert_contains('data-admin-theme', $spec, 'снимаем обе темы панели');

    // Фикстура заводит администратора с известным паролем — на боевой базе
    // это дыра, поэтому проверка окружения обязана быть в скрипте.
    assert_contains("Config::get('app.env'", $seed);
    assert_contains("['testing', 'development']", $seed);
    assert_contains('Cli::assertCli()', $seed);

    assert_contains('php tests/browser/seed_admin_visual.php', $ci, 'CI создаёт фикстуру перед прогоном');
    assert_contains('npm run test:visual', $ci);

    // Эталон снят и лежит в репозитории: без файлов первый прогон в CI просто
    // запишет их заново и ничего не сверит.
    $baseline = glob(APP_ROOT . '/tests/browser/visual-baseline/admin-*.json') ?: [];
    assert_true(count($baseline) >= 10, 'ожидается эталон по экранам панели в обеих темах, найдено: ' . count($baseline));

    foreach ($baseline as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        assert_true(is_array($data) && $data !== [], basename($file) . ': эталон пуст');
        foreach ($data as $selector => $probe) {
            assert_true(
                $probe !== 'нет на странице',
                basename($file) . ": зонд «{$selector}» ничего не нашёл — снимается несуществующий элемент"
            );
        }
    }
});
