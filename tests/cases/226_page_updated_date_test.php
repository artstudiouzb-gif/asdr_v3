<?php

declare(strict_types=1);

use App\Core\DateFormatter;

// Форматирование дат остаётся доступно другим публичным компонентам, но
// служебная строка последнего обновления больше не выводится под страницей.

test('Дата обновления: длинный формат на всех языках публички', function () {
    assert_same('8 августа 2026 г.', DateFormatter::long('2026-08-08 11:20:00', 'ru'));
    assert_same('8-avgust, 2026-yil', DateFormatter::long('2026-08-08 11:20:00', 'uz'));
    assert_same('August 8, 2026', DateFormatter::long('2026-08-08 11:20:00', 'en'));
    // Пустое значение из БД не должно превращаться в «1 января 1970».
    assert_same('', DateFormatter::long('0000-00-00 00:00:00', 'ru'));
});

test('Дата обновления: строка переведена на узбекский и английский', function () {
    foreach (['uz', 'en'] as $lang) {
        $dictionary = require APP_ROOT . '/app/Core/lang/' . $lang . '.php';
        assert_true(isset($dictionary['Обновлено']), 'нет перевода «Обновлено» в ' . $lang);
        assert_true(trim((string) $dictionary['Обновлено']) !== '', 'пустой перевод в ' . $lang);
    }
});

test('Служебная дата обновления не выводится в публичном шаблоне страницы', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/page.php');
    assert_not_contains('page-updated', $view);
    assert_not_contains("Lang::t('Обновлено')", $view);
    assert_not_contains("\$page['updated_at']", $view);
});
