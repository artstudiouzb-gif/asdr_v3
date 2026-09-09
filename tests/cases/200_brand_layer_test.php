<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

/**
 * Фирменный слой: карточки правовых актов и автоматическое оглавление
 * страницы с закреплённой полосой.
 */

function brand_block(int $id, string $type, array $data): array
{
    return [
        'id' => $id,
        'type' => $type,
        'custom_css' => '',
        'data' => json_encode(array_merge(BlockRenderer::defaultsFor($type), $data)),
    ];
}

function brand_html(int $id, string $type, array $data): string
{
    return (string) BlockRenderer::render(brand_block($id, $type, $data))['html'];
}

test('Акты: номер и дата выводятся отдельной карточкой', function () {
    $html = brand_html(940, 'docs_list', [
        'variant' => 'acts',
        'title' => 'Правовая основа',
        'items' => [
            ['title' => 'Об утверждении Стратегии', 'number' => 'УП-6079', 'date' => '12 марта 2026 г.', 'url' => '/uploads/public/act.pdf'],
        ],
    ]);

    assert_contains('act-card', $html);
    assert_contains('УП-6079', $html);
    assert_contains('12 марта 2026 г.', $html);
    // Реквизиты ищутся вместе с названием: акт находят как раз по номеру.
    assert_contains('data-document-search', $html);
    assert_true(str_contains($html, 'уп-6079'), 'номер попадает в строку поиска');
});

test('Акты: обычные варианты списка не меняются', function () {
    $items = [['title' => 'Отчёт', 'number' => 'УП-1', 'url' => '/uploads/public/act.pdf']];

    $grid = brand_html(941, 'docs_list', ['variant' => 'grid', 'items' => $items]);
    assert_contains('doc-card', $grid);
    assert_not_contains('act-card', $grid);
    // Номер сохраняется в данных, но в карточке документа его не показывают.
    assert_not_contains('УП-1', $grid);

    $links = brand_html(942, 'docs_list', ['variant' => 'links', 'items' => $items]);
    assert_contains('doc-card--compact', $links);
    assert_not_contains('act-card', $links);
});

test('Акты: карточка без ссылки не притворяется ссылкой', function () {
    $html = brand_html(943, 'docs_list', [
        'variant' => 'acts',
        'items' => [['title' => 'Готовится к публикации', 'number' => 'ПП-2']],
    ]);

    assert_contains('<div', $html);
    assert_not_contains('<a class="act-card"', $html);
});

test('Оглавление: собирается из заголовков блоков страницы', function () {
    $page = BlockRenderer::renderPage([
        brand_block(950, 'hero', ['title' => 'Устойчивый рост', 'bg_type' => 'image', 'image' => '/uploads/public/a.jpg']),
        brand_block(951, 'anchor_nav', ['auto' => true, 'sticky' => true]),
        brand_block(952, 'text', ['title' => 'О направлении', 'content' => '<p>Текст раздела</p>']),
        brand_block(953, 'docs_list', ['title' => 'Правовая основа', 'items' => [['title' => 'Акт', 'url' => '/uploads/public/a.pdf']]]),
    ]);

    $html = (string) $page['html'];
    assert_contains('href="#block-952"', $html);
    assert_contains('href="#block-953"', $html);
    assert_contains('О направлении', $html);
    // Обложка — заголовок всей страницы, а не её раздел; сама навигация в
    // свой же список тоже не попадает.
    assert_not_contains('href="#block-950"', $html);
    assert_not_contains('href="#block-951"', $html);
    assert_contains('block-anchornav--sticky', $html);
    assert_true(in_array('anchor_nav', $page['assets'], true), 'подсветка раздела требует скрипта');
});

test('Оглавление: ручные пункты работают и без автосборки', function () {
    $page = BlockRenderer::renderPage([
        brand_block(960, 'anchor_nav', ['items' => [['label' => 'Обзор', 'url' => '#block-961']]]),
        brand_block(961, 'text', ['title' => 'Раздел', 'content' => '<p>Текст</p>']),
    ]);

    $html = (string) $page['html'];
    assert_contains('href="#block-961"', $html);
    assert_contains('Обзор', $html);
    // Без галочки автосборки заголовки блоков в навигацию не добавляются.
    assert_not_contains('>Раздел</a>', $html);
    assert_not_contains('block-anchornav--sticky', $html);
});

test('Оглавление: скрытый по расписанию раздел в список не попадает', function () {
    $page = BlockRenderer::renderPage([
        brand_block(970, 'anchor_nav', ['auto' => true]),
        brand_block(971, 'text', ['title' => 'Действующий', 'content' => '<p>Текст</p>']),
        brand_block(972, 'text', ['title' => 'Просроченный', 'content' => '<p>Текст</p>', '_visible_to' => '2020-01-01T00:00']),
    ]);

    $html = (string) $page['html'];
    assert_contains('href="#block-971"', $html);
    assert_not_contains('href="#block-972"', $html);
});

test('Эмблема агентства подключена файлом и лежит на месте', function () {
    $css = theme_css();
    $svg = dirname(__DIR__, 2) . '/public/assets/img/emblem.svg';

    assert_contains('--gov-emblem: url("../img/emblem.svg")', $css);
    assert_true(is_file($svg), 'эмблема должна лежать рядом со стилями');
    // Маска берёт форму из путей: заливка и размеры внутри файла не нужны,
    // а вот viewBox обязателен — без него маска схлопывается.
    assert_contains('viewBox=', (string) file_get_contents($svg));
    // Тонкую линейную эмблему легко перекрыть текстом — водяной знак уходит
    // под содержимое карточки.
    assert_contains('.act-card__head, .act-card__desc, .act-card__foot { position: relative; z-index: 1; }', $css);
});

test('Оглавление: закреплённая полоса не перекрывает якоря', function () {
    $css = theme_css();
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/blocks/anchor_nav.js');

    // Липкой должна быть секция: sticky внутри низкого блока уезжает вместе с ним.
    assert_contains('.cms-block:has(> .block-anchornav--sticky)', $css);
    assert_contains('scroll-margin-top: var(--anchornav-top', $css);
    // Высота шапки здесь не учитывается: её держит scroll-padding-top у html —
    // один отступ на все якоря сайта. Две константы разъезжались бы молча, и
    // якорь блока уезжал бы вдвое ниже, чем якорь вопроса или сектора команды.
    $frontend = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');
    assert_contains('scroll-padding-top: calc(var(--scroll-offset', $frontend);
    assert_false(str_contains($css, 'var(--anchornav-top, 0px) + 64px'), 'отступ шапки задан дважды');
    // Обе высоты меряются, а не подбираются константой.
    assert_contains("setProperty('--anchornav-top'", $js);
    $frontendJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/frontend.js');
    assert_contains("setProperty('--scroll-offset'", $frontendJs);
    assert_contains('IntersectionObserver', $js);
});

test('Эмблему можно заменить своим SVG из настроек дизайна', function () {
    \App\Models\Setting::overrideInMemory('design_emblem', '/uploads/public/gerb.svg');
    $css = \App\Core\SiteThemeCss::build([], \App\Core\HeaderConfig::DEFAULTS, false);
    assert_contains('--gov-emblem:url("/uploads/public/gerb.svg")', $css);

    // Чужой адрес и попытка вырваться из url() темой игнорируются: значение
    // уходит прямо в CSS, поэтому проверяется до подстановки.
    foreach (['https://example.com/x.svg', '/uploads/public/a.svg") ;}body{display:none;/*'] as $bad) {
        \App\Models\Setting::overrideInMemory('design_emblem', $bad);
        assert_not_contains('--gov-emblem', \App\Core\SiteThemeCss::build([], \App\Core\HeaderConfig::DEFAULTS, false));
    }

    \App\Models\Setting::overrideInMemory('design_emblem', '');
    assert_not_contains('--gov-emblem', \App\Core\SiteThemeCss::build([], \App\Core\HeaderConfig::DEFAULTS, false));

    // Поле есть в форме дизайна, иначе настройку негде задать.
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/design/index.php');
    assert_contains("imageField('emblem'", $form);
    assert_contains('enctype="multipart/form-data"', $form);
});
