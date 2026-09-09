<?php

declare(strict_types=1);

use App\Core\AccentContrast;

test('Акцент: контраст считается по формуле WCAG', function () {
    assert_same(21.0, AccentContrast::ratio('#000000', '#ffffff'), 'чёрный на белом — предельные 21:1');
    assert_same(1.0, AccentContrast::ratio('#123456', '#123456'), 'цвет сам с собой — 1:1');
    // Бирюзовый акцент по умолчанию на белом не дотягивает до нормы AA.
    assert_true(AccentContrast::ratio('#17999b', '#ffffff') < AccentContrast::AA_NORMAL);
});

test('Акцент: производные всегда дотягивают до нормы AA', function () {
    // Проверяем на разных по светлоте акцентах, включая заведомо неудобные:
    // жёлтый почти сливается с белым, тёмно-синий — с фоном навигации.
    foreach (['#17999b', '#0ea5e9', '#c0392b', '#f1c40f', '#173a63', '#ffffff', '#000000'] as $accent) {
        $onLight = AccentContrast::onLight($accent, '#ffffff');
        $onDark = AccentContrast::onDark($accent, '#173a63');

        assert_true(
            AccentContrast::ratio($onLight, '#ffffff') >= AccentContrast::AA_NORMAL,
            "{$accent}: вариант для светлого фона не проходит AA"
        );
        assert_true(
            AccentContrast::ratio($onDark, '#173a63') >= AccentContrast::AA_NORMAL,
            "{$accent}: вариант для тёмного фона не проходит AA"
        );
    }
});

test('Акцент: проходящий цвет не меняется, мусор не роняет расчёт', function () {
    // Тёмно-красный уже контрастен на белом — трогать его незачем.
    assert_same('#c0392b', AccentContrast::onLight('#c0392b', '#ffffff'));
    // Короткая запись и мусор не должны приводить к исключению.
    assert_same('#ffffff', AccentContrast::toHex(AccentContrast::toRgb('#fff')));
    assert_true(AccentContrast::onLight('не цвет') !== '');
    assert_true(AccentContrast::onDark('') !== '');
});

test('Тема отдаёт вычисленные варианты акцента, а не константы', function () {
    $theme = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/SiteThemeCss.php');
    assert_contains('AccentContrast::onLight', $theme);
    assert_contains('AccentContrast::onDark', $theme);
    assert_contains('--gov-teal-on-dark', $theme);

    $css = theme_css();
    // На тёмных подложках текстовый акцент переключается на светлый вариант.
    assert_contains('.cms-block--bg-navy, .block-hero--media, .site-footer { --gov-teal-text: var(--gov-teal-on-dark); }', $css);
    // Мелкие ссылки берут текстовый вариант, а не «графический» акцент.
    assert_contains('.block-hero__eyebrow', $css);
    assert_not_contains('.newsfeat__more { color: var(--gov-teal);', $css);
    assert_not_contains('.person-card__more { color: var(--gov-teal);', $css);
});
