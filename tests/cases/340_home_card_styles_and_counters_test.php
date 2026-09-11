<?php

declare(strict_types=1);

/*
 * Проверка сохранения высоты счетчиков, рамок медиатеки, hover-теней
 * и настроек «Стиля карточек» на главной странице.
 *
 * Компактные стили главной страницы не должны ломать глобальные настройки темы:
 * - Блок счётчиков должен сохранять пропорции и высоту из gov-theme.css;
 * - Медиакарточки должны сохранять рамку, фон и скругление;
 * - Карточки должны иметь тень и подъём при наведении (:hover);
 * - «Стиль карточек» (flat/soft/elevated) через --card-shadow должен работать
 *   на карточках главной без принудительного `box-shadow: none`.
 */

test('public-home.css не сплющивает блок счётчиков и не перебивает его отступы', function () {
    $homeCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-home.css');

    assert_not_contains('.block-counters--panel-card', $homeCss, 'Счётчики не должны переопределяться в public-home.css');
    assert_not_contains('.block-counters--row', $homeCss, 'Счётчики не должны переопределяться в public-home.css');
    assert_not_contains('margin-top: -24px', $homeCss, 'Счётчики должны сохранять оригинальное перекрытие hero');
});

test('public-home.css не снимает рамку, фон и скругление с карточек медиатеки', function () {
    $homeCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-home.css');

    assert_not_contains('.mediacard', $homeCss, 'Медиакарточки не должны переопределяться в public-home.css');
});

test('public-home.css не подавляет тени и трансформации карточек на :hover', function () {
    $homeCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-home.css');

    assert_not_contains('transform: none; box-shadow: none', $homeCss, 'Hover-тени карточек должны работать');
    assert_not_contains(':hover { transform: none', $homeCss);
});

test('Карточки главной страницы подчиняются настройке «Стиль карточек»', function () {
    $homeCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-home.css');

    // feature-card не должен принудительно выставлять box-shadow: none
    preg_match('/\.site-home\s+\.feature-card\s*\{([^}]*)\}/', $homeCss, $m);
    $cardBody = $m[1] ?? '';
    assert_not_contains('box-shadow: none', $cardBody, 'feature-card должен наследовать --card-shadow');
    assert_not_contains('box-shadow:', $cardBody, 'feature-card не должен жестко задавать тень');
});

test('Новости в колонках (.news-column) и карточки новостей имеют тень и подъём при наведении', function () {
    $newsCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-feature.css');
    $homeCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-home.css');

    // .news-column включён в список интерактивных карточек с наследованием тени и подъёма
    assert_contains('.news-column', $newsCss);
    assert_true(
        (bool) preg_match('/:is\([^)]*\.news-column[^)]*\):hover/s', $newsCss),
        '.news-column должен входить в интерактивный :is(...):hover в news-feature.css'
    );

    // На главной странице новостные карточки имеют hover-подъём и тень
    assert_contains('.site-home :is(.news-column, .news-card, .newslist-lead, .relnews-card):hover', $homeCss);
    // Цвет тени — основной цвет сайта, а не литерал: #2563eb это акцент
    // ПАНЕЛИ, к публичной палитре отношения не имеющий, и на тёплой или
    // тёмной палитре синий ореол под карточкой читался чужим.
    assert_contains(
        'box-shadow: 0 16px 36px color-mix(in srgb, var(--gov-navy) 14%, transparent);',
        $homeCss
    );
    assert_contains('transform: translateY(var(--feature-card-hover-lift, -4px));', $homeCss);
});

test('Ссылки в новостях при наведении меняют цвет без подчёркивания', function () {
    $newsCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-feature.css');
    $homeCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-home.css');

    // .news-column:hover не должен иметь text-decoration: underline
    assert_not_contains('.news-column:hover .news-column__title { text-decoration: underline', $newsCss);
    assert_contains('.news-column:hover .news-column__title { color: var(--gov-teal); text-decoration: none; }', $newsCss);
    assert_contains('text-decoration: none;', $homeCss);
});

test('Блок счётчиков не имеет подъёма (translateY) при наведении', function () {
    $countersCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');

    assert_not_contains('.block-counters:hover { transform: translateY', $countersCss);
    assert_not_contains('.counter:hover { transform: translateY', $countersCss);
    assert_contains('.block-counters:hover {', $countersCss);
});

