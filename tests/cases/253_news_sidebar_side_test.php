<?php

declare(strict_types=1);

// «Макет страницы с виджетами» выбирает сторону боковой колонки. Раньше выбор
// влиял только на то, ОТКУДА берутся виджеты (WidgetRenderer::sidebarFor
// запрашивал колонку 'left'), а показывались они всё равно справа: в разметке
// стоял жёсткий класс --right.

test('Сторона боковой колонки приходит из настройки, а не зашита в разметку', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    assert_contains("\$sidebar['position']", $view, 'сторона колонки снова зашита в разметку');
    assert_contains('newsdetail-side--left', $view);
    assert_contains('newsdetail-side--right', $view);

    // Разметка не переставляется: статья обязана идти в DOM раньше колонки,
    // иначе диктор и Tab получают виджеты вперёд содержимого.
    $article = strpos($view, 'newsdetail-article__content');
    $aside = strpos($view, '<aside class="newsdetail-side');
    assert_true(
        $article !== false && $aside !== false && $article < $aside,
        'колонка уехала в разметке выше статьи — влево её двигает сетка, а не порядок тегов'
    );
});

test('Левая колонка переставляет всю сетку, а не только себя', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');

    // У обычных макетов .newsdetail-body раскрыт в display: contents, поэтому
    // поимённо размещены и шапка, и статья. Переставить надо все три, иначе
    // карточки ложатся поверх текста (так и было при первой попытке).
    foreach ([
        '.newsdetail:not(.newsdetail--premium):has(.newsdetail-side--left) > .newsdetail-head',
        '.newsdetail:not(.newsdetail--premium):has(.newsdetail-side--left) .newsdetail-article',
        '.newsdetail:not(.newsdetail--premium) .newsdetail-side--left',
    ] as $selector) {
        assert_contains($selector, $css, 'не переставлено при левой колонке: ' . $selector);
    }

    // «Одна колонка по центру» обязана исключать и левую сторону: без этого
    // сетка схлопывалась в 900px и всё наезжало друг на друга.
    assert_contains(
        ':not(:has(.newsdetail-side--right > *)):not(:has(.newsdetail-side--left > *))',
        $css,
        'центрирование одной колонки снова сработает при левом сайдбаре'
    );
});

test('Боковая колонка складывается до того, как статья станет узкой', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');

    // При фиксированных 340px сайдбара статья на 768px сжималась до 362px —
    // 38 знаков в строке. Складываем на 900, а не на 720.
    assert_contains('@media (max-width: 900px) { .newsdetail-body { grid-template-columns: 1fr; }', $css);
    assert_not_contains(
        '@media (max-width: 720px) { .newsdetail-body { grid-template-columns: 1fr; }',
        $css,
        'колонка снова складывается только на 720px'
    );

    // Левое оформление живёт под парным min-width: правила стоят в файле ниже
    // блока max-width и без пары перебивали складывание — на 390px сетка
    // оставалась 340px + 1fr и давала прокрутку вбок.
    assert_contains('@media (min-width: 901px)', $css);
});
