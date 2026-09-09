<?php

declare(strict_types=1);

/**
 * Типографика контентных страниц: ширина текста и вес ссылок.
 *
 * Мера строки в 65–80 знаков — типографская норма, но владелец выбрал полную
 * ширину колонки: страницы агентства это в основном перечни и документы, и
 * узкая колонка оставляла справа пустое поле. Токен --rich-measure остался у
 * заголовка текстового блока.
 */

test('Контент: ширину текста новости не ограничиваем', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/rich-content.css');

    assert_not_contains(':where(.rich-content) > :is(p, ul, ol, dl, h2, h3, h4, h5, h6, blockquote)', $css);

    // Меры строки не осталось и у заголовка блока: он занимает всю ширину
    // колонки, как и текст под ним. Токен удалён, чтобы не тянуть за собой
    // неиспользуемую «половинную» ширину.
    assert_not_contains('--rich-measure:', $css);
    $theme = theme_css();
    assert_contains('.block-text__title { max-width: none; }', $theme);
});

test('Лид новости набран как текст статьи и занимает контейнер', function () {
    $theme = theme_css();
    $pos = (int) strpos($theme, '.newsdetail__lead {');
    assert_true($pos > 0, 'правило лида на месте');
    $rule = substr($theme, $pos, 460);

    // Ширина — по контейнеру: раньше лид обрывался на 52ch и выглядел вдвое
    // уже текста под ним.
    assert_not_contains('max-width', $rule);
    assert_contains('font-size: var(--step-0);', $rule);
    assert_contains('line-height: 1.6;', $rule);

    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/rich-content.css');
    // Первый абзац больше не набирается «вводкой»: лид у новости уже есть в
    // шапке, и один и тот же текст выглядел на странице дважды и по-разному.
    assert_not_contains('.newsdetail-article__content.rich-content > p:first-child {', $css);
    // Полужирный — выделение, а не смена цвета.
    assert_contains(':where(.rich-content) strong { color: inherit;', $css);
});

test('Контент: маркер списка — восьмиконечная звезда', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/rich-content.css');

    // Руб-эль-Хизб: восемь внешних вершин на радиусе R и восемь внутренних на
    // 0.765R. Форма держится от 10px, поэтому размер задан в пикселях.
    assert_contains(':where(.rich-content) ul > li::before', $css);
    assert_contains('50% 0%, 64.7% 14.6%, 85.4% 14.6%', $css);
    assert_contains('width: 10px;', $css);
    // Нумерованные списки остаются с обычными цифрами.
    assert_contains(':where(.rich-content) ol li::marker', $css);
});

test('Контент: ссылки в тексте не полужирные', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/rich-content.css');

    // Страница с перечнем указов состоит из ссылок: полужирное начертание
    // превращало её в сплошной акцент.
    assert_false(
        (bool) preg_match('/:where\(\.rich-content\) a \{[^}]*font-weight:\s*[6-9]00/s', $css),
        'ссылки в тексте не должны быть полужирными'
    );
});

test('Контент: первый блок не отбивается от шапки страницы дважды', function () {
    $theme = theme_css();

    assert_contains('.content-pagehead + .cms-block,', $theme);
    assert_contains('.content-pagehead + .layout .cms-block:first-child', $theme);
});
