<?php

declare(strict_types=1);

/*
 * Проверка редакционной верстки, типографики и улучшения вариантов блока новостей.
 *
 * Требования:
 * 1. Типографика: text-wrap: balance для заголовков, tabular-nums для счетчиков и дат.
 * 2. Выравнивание карточек (flex baseline alignment) и пружинная анимация (--ease-spring).
 * 3. Варианты блока новостей (columns, cards, mosaic):
 *    - пропорция обложки 16 / 10 для columns;
 *    - микро-кинетика hover zoom для изображений;
 *    - кнопка перехода ко всем новостям (.newsfeat-more__btn);
 *    - сохранение строгих селекторов регрессионных тестов (340 и 276).
 * 4. Мобильная эргономика (touch targets >= 44px на pointer: coarse).
 */

test('public-layout-polish.css содержит редакционную типографику и tabular-nums', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');

    assert_contains('text-wrap: pretty;', $css, 'Текстовые блоки должны использовать text-wrap: pretty для исключения висячих строк');
    assert_contains('font-variant-numeric: tabular-nums lining-nums;', $css, 'Числа и даты должны быть моноширинными');
    assert_contains('--ease-spring: cubic-bezier(0.16, 1, 0.3, 1);', $css, 'Пружинная физика должна быть объявлена в :root');
    assert_contains('.counter__number', $css, 'Счетчики должны входить в правило tabular-nums');
    assert_contains('min-height: 44px;', $css, 'Интективные элементы на тач-экранах должны быть не менее 44px');
});

test('public-layout-polish.css обеспечивает выравнивание подвала карточек по базовой линии', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');

    assert_contains(':where(.news-card, .relnews-card, .project-card, .album-card, .news-column)', $css);
    assert_contains('margin-top: auto;', $css, 'Метаданные карточек должны прижиматься к нижней границе');
});

test('news-feature.css содержит улучшенные пропорции и анимацию колонок новостей', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-feature.css');

    assert_contains('aspect-ratio: 16 / 10;', $css, 'Обложка в колонках новостей имеет благородную пропорцию 16/10');
    assert_contains('.news-column:hover .news-column__image { transform: scale(1.035); }', $css, 'Плавный микро-зум кадра при наведении');
    assert_contains('.newsfeat-more__btn', $css, 'Кнопка «Все новости» оформлена в редакционном стиле');
    assert_contains('.news-column:hover .news-column__arrow { border-color: var(--gov-teal); color: var(--gov-teal); transform: translateX(4px);', $css);
});

test('news-feature.css сохраняет регрессионные инварианты из тестов 340 и 276', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-feature.css');

    // Инвариант теста 340: hover на колонке не должен подчеркивать заголовок
    assert_contains(
        '.news-column:hover .news-column__title { color: var(--gov-teal); text-decoration: none; }',
        $css,
        'Инвариант 340_home_card_styles_and_counters_test должен сохраняться'
    );

    // Инвариант теста 276: цвет заголовка и даты не должен затирать крупные hero/wide карточки
    assert_contains(
        '.newslist-grid .relnews-card:not(.relnews-card--hero):not(.relnews-card--wide) .relnews-card__title',
        $css,
        'Инвариант 276_news_feed_rhythm_test для заголовка должен сохраняться'
    );
    assert_contains(
        '.newslist-grid .relnews-card:not(.relnews-card--hero):not(.relnews-card--wide) .relnews-card__date',
        $css,
        'Инвариант 276_news_feed_rhythm_test для даты должен сохраняться'
    );
});
