<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

/*
 * «Карточки»: раскладки, меняющие композицию секции, — «Ведущая карточка»,
 * «Указатель», «Знак на фоне» и «Панель». Поля у них те же, что у «Иконки и
 * текста», поэтому переключение варианта ничего не теряет.
 */

function cards_layout_render(string $variant, array $items): array
{
    return BlockRenderer::render(['id' => 431, 'type' => 'cards_grid', 'custom_css' => null, 'data' => json_encode([
        'title' => 'Направления', 'variant' => $variant, 'items' => $items,
    ])]);
}

test('Карточки: четыре раскладки выводят каждый пункт целиком', function (): void {
    $items = [
        ['icon_svg' => 'target', 'title' => 'Мониторинг целей', 'text' => 'Контроль исполнения', 'url' => '/goals'],
        ['icon_svg' => 'users', 'title' => 'Экспертная сеть', 'text' => 'Эксперты', 'url' => 'javascript:alert(1)'],
    ];
    foreach (['lead', 'index', 'mark', 'panel'] as $variant) {
        $r = cards_layout_render($variant, $items);
        $html = $r['html'];
        assert_contains('class="cards-' . $variant . '"', $html, $variant);
        assert_contains('<h3 class="cards-' . $variant . '__title">Мониторинг целей</h3>', $html, $variant);
        assert_contains('<p class="cards-' . $variant . '__text">Контроль исполнения</p>', $html, $variant);
        assert_contains('href="/goals"', $html, $variant);
        assert_contains('tabler-target', $html, $variant);
        assert_not_contains('javascript:', $html, 'ссылка проверяется на выводе: ' . $variant);
        assert_contains('<div class="cards-' . $variant . '__item">', $html, 'пункт без ссылки — не ссылка: ' . $variant);
        assert_not_contains(' style="', $html, $variant);
    }

    assert_contains('cards-panel-box', cards_layout_render('panel', $items)['html'], 'шапка панели внутри тёмной подложки');
    assert_contains('cards-index__go', cards_layout_render('index', $items)['html'], 'у строки-ссылки есть стрелка');
    assert_contains('--cards-emblem:url(', cards_layout_render('lead', $items)['css'], 'эмблема — абсолютным адресом');
});

test('Карточки: у раскладок есть рисунок и пояснение в форме, стили подключаются по типу', function (): void {
    $html = \App\Core\BlockData\BlockFieldSchema::formHtml('cards_grid', ['variant' => 'lead']);
    foreach (['Ведущая карточка', 'Указатель', 'Знак на фоне', 'Панель'] as $label) {
        assert_contains($label, $html);
    }
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/cards-layouts.css');
    foreach (['.cards-lead', '.cards-index', '.cards-mark', '.cards-panel'] as $selector) {
        assert_contains($selector . ' {', $css, $selector);
    }
});
