<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

// Сетки блоков «Преимущества» и «Этапы» считают колонки от числа элементов:
// пять карточек при четырёх колонках давали ряд 4+1 с одинокой карточкой, а
// четыре этапа при жёстких пяти колонках обрывали линию хронологии.

/** Собирает CSS блока с заданным числом однотипных элементов. */
function grid_block_css(string $type, int $count, array $extra = []): string
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = ['title' => 'Пункт ' . $i, 'text' => 'Описание', 'year' => (string) (2020 + $i)];
    }
    $rendered = BlockRenderer::render([
        'id' => 500 + $count,
        'type' => $type,
        'data' => json_encode($extra + ['items' => $items], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) ($rendered['css'] ?? '');
}

test('Преимущества: в последнем ряду не остаётся одинокой карточки', function () {
    assert_same(2, \App\Core\GridBalance::columnsFor(2));
    assert_same(3, \App\Core\GridBalance::columnsFor(3));
    assert_same(4, \App\Core\GridBalance::columnsFor(4));
    assert_same(3, \App\Core\GridBalance::columnsFor(5), 'пятёрка раскладывается 3+2, а не 4+1');
    assert_same(4, \App\Core\GridBalance::columnsFor(6));
    assert_same(4, \App\Core\GridBalance::columnsFor(7));
    assert_same(4, \App\Core\GridBalance::columnsFor(8));
    assert_same(3, \App\Core\GridBalance::columnsFor(9), 'девятка ложится 3+3+3, а не 4+4+1');

    foreach (range(2, 12) as $count) {
        $cols = \App\Core\GridBalance::columnsFor($count);
        assert_true($count % $cols !== 1, "остаётся одинокая карточка: {$count} элементов в {$cols} колонок");
    }
});

test('Преимущества: до пяти карточек — один ряд, дальше хвост во всю ширину', function () {
    // Пять карточек идут пятёркой в один ряд: делить их 3+2 незачем.
    $css = grid_block_css('advantages', 5, ['variant' => 'grid']);
    assert_contains('--grid-track:5', $css);
    assert_contains('--grid-span:1', $css);
    assert_not_contains('nth-last-child', $css);

    // Семь карточек в один ряд не помещаются: три дорожки по два деления,
    // хвост растягивается — пустых ячеек справа нет.
    $tail = grid_block_css('advantages', 7, ['variant' => 'grid']);
    assert_contains('--grid-span:3', $tail);
    assert_contains(':nth-last-child(-n+3)', $tail);
    // Мобильную раскладку задаёт тема, поэтому растяжение только на десктопе.
    assert_contains('@media (min-width:901px)', $tail);

    // Восьмёрка делится на четыре колонки нацело — хвостового правила нет.
    $even = grid_block_css('advantages', 8, ['variant' => 'grid']);
    assert_contains('--grid-track:4', $even);
    assert_contains('--grid-span:1', $even);
    assert_not_contains('nth-last-child', $even);
});

test('Правовые акты: пятая карточка не оставляет дыру справа', function () {
    $items = [];
    for ($i = 1; $i <= 5; $i++) {
        $items[] = ['title' => 'Акт ' . $i, 'number' => '№ ' . $i, 'date' => '2025', 'url' => '', 'meta' => ''];
    }
    $rendered = BlockRenderer::render([
        'id' => 700,
        'type' => 'docs_list',
        'data' => json_encode(['variant' => 'acts', 'columns' => 3, 'items' => $items], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);
    $css = (string) $rendered['css'];
    assert_contains('.docslist-acts{--grid-track:6;--grid-span:2}', $css);
    assert_contains('.act-card:nth-last-child(-n+2){grid-column:span 3}', $css);
});

test('Этапы: колонок ровно по числу этапов, дальше карусель', function () {
    $columns = static function (int $count): int {
        $css = grid_block_css('stages', $count);
        preg_match('/--stages-count:(\d+)/', $css, $m);

        return (int) ($m[1] ?? 0);
    };

    assert_same(3, $columns(3));
    assert_same(4, $columns(4), 'четыре этапа занимают всю ширину, без пустой пятой колонки');
    assert_same(5, $columns(5));
    // Больше пяти — горизонтальная карусель, ширину колонки задаёт она сама.
    assert_same(5, $columns(7));
});

test('Карточки персон: «Вакантно» только у карточки без имени', function () {
    $render = static function (array $items): string {
        $result = BlockRenderer::render([
            'id' => 640,
            'type' => 'person_cards',
            'data' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE),
            'custom_css' => '',
        ]);

        return (string) $result['html'];
    };

    // Руководитель без загруженной фотографии — не вакансия.
    $named = $render([['photo' => '', 'name' => 'Иванов Иван', 'role' => 'Директор', 'url' => '/direktor']]);
    assert_contains('Иванов Иван', $named);
    assert_not_contains('Вакантно', $named);
    assert_not_contains('person-card--vacant', $named);

    $vacant = $render([['photo' => '', 'name' => '', 'role' => 'Заместитель директора', 'url' => '']]);
    assert_contains('Вакантно', $vacant);
    assert_contains('person-card--vacant', $vacant);
    assert_contains('Заместитель директора', $vacant);
});

test('Контент блоков не зажат мерой строки в ch', function () {
    $css = theme_css();
    // Колонка в ch обрывала текст раньше, чем кончался контейнер: заголовок
    // текстового блока, лид страницы и текст профиля жили в своей ширине.
    foreach (['.block-text__title', '.content-pagehead__lead', '.profile__text', '.listing__lead', '.catdetail__body'] as $selector) {
        $offset = strpos($css, $selector . ' {');
        assert_true($offset !== false, 'нет правила ' . $selector);
        $rule = substr($css, $offset, (int) strpos($css, '}', $offset) - $offset);
        assert_true(
            preg_match('/max-width:\s*[\d.]+ch/', $rule) !== 1,
            'ширина в ch вернулась в ' . $selector
        );
    }
    // Исключения оставлены осознанно: заголовок поверх фотографии в шапке
    // новости и режим чтения, где узкая колонка — сама функция.
    assert_contains('.newsdetail-phero__title', $css);
});

test('Этапы: сплошной полосы во всю ширину нет — линия кончается на последней точке', function () {
    $css = theme_css();
    // Полоса .stages::before тянулась от left:0 до right:0 и продолжалась
    // за последнюю точку хвостом на всю пустую часть ряда.
    assert_not_contains('.stages::before', $css);
    assert_contains('.stage:not(:last-child)::before', $css);
});
