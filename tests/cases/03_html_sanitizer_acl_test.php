<?php

declare(strict_types=1);

use App\Core\HtmlSanitizer;
use App\Core\BlockRenderer;

// HTML любого автора проходит через HtmlSanitizer, поэтому
// <script>/style/on*/javascript: не могут быть внедрены.
test('HtmlSanitizer: вырезает <script>', function () {
    $out = HtmlSanitizer::sanitize('<p>Привет</p><script>alert(1)</script>');
    assert_not_contains('<script', $out);
    assert_contains('Привет', $out);
});

test('HtmlSanitizer: удаляет обработчики on*', function () {
    $out = HtmlSanitizer::sanitize('<a href="/x" onclick="steal()">клик</a>');
    assert_not_contains('onclick', $out);
    assert_contains('клик', $out);
});

test('HtmlSanitizer: убирает javascript:-ссылки', function () {
    $out = HtmlSanitizer::sanitize('<a href="javascript:alert(1)">x</a>');
    assert_not_contains('javascript:', $out);
});

test('HtmlSanitizer: сохраняет разрешённое форматирование', function () {
    $out = HtmlSanitizer::sanitize('<p><strong>жирный</strong> и <em>курсив</em></p>');
    assert_contains('<strong>', $out);
    assert_contains('<em>', $out);
});

test('HtmlSanitizer: удаляет inline-стили', function () {
    $out = HtmlSanitizer::sanitize('<p style="color:red">Текст</p>');
    assert_not_contains('style=', $out);
    assert_contains('<p>Текст</p>', $out);
});

test('HtmlSanitizer: разворачивает запрещённые теги, сохраняя текст', function () {
    $out = HtmlSanitizer::sanitize('<iframe src="//evil">видимый текст</iframe>');
    assert_not_contains('<iframe', $out);
    assert_contains('видимый текст', $out);
});

test('HtmlSanitizer: target=_blank получает rel=noopener', function () {
    $out = HtmlSanitizer::sanitize('<a href="https://example.com" target="_blank">x</a>');
    assert_contains('noopener', $out);
});

test('HtmlSanitizer: вырезает скрипты, вложенные в запрещённые теги (svg/math)', function () {
    $out = HtmlSanitizer::sanitize('<div><svg><script>alert(1)</script></svg></div>');
    assert_not_contains('<script', $out);
    assert_not_contains('alert', $out);

    $out2 = HtmlSanitizer::sanitize('<div><math><script>alert(2)</script></math></div>');
    assert_not_contains('<script', $out2);
    assert_not_contains('alert', $out2);
});

test('HTML-блок повторно очищает старые сохранённые script и style', function () {
    $rendered = BlockRenderer::render([
        'id' => 303,
        'type' => 'html',
        'custom_css' => null,
        'data' => json_encode([
            'html' => '<p style="color:red" onclick="alert(1)">Текст</p><script>alert(2)</script>',
        ]),
    ]);

    assert_contains('<p>Текст</p>', $rendered['html']);
    assert_not_contains(' style=', $rendered['html']);
    assert_not_contains('onclick', $rendered['html']);
    assert_not_contains('<script', $rendered['html']);
});

test('Текстовый блок очищает script и style в старом rich text', function () {
    $rendered = BlockRenderer::render([
        'id' => 304,
        'type' => 'text',
        'custom_css' => null,
        'data' => json_encode([
            'title' => 'Текст',
            'content' => '<p style="color:red">Абзац</p><script>alert(1)</script>',
        ]),
    ]);

    assert_contains('<p>Абзац</p>', $rendered['html']);
    assert_not_contains(' style=', $rendered['html']);
    assert_not_contains('<script', $rendered['html']);
});

// SVG регистрозависим, а HTML-разбор опускает регистр имён: без обратного
// восстановления вставленная в блок графика теряла масштаб и заливку.
test('HtmlSanitizer: сохраняет регистр viewBox и ссылку на символ спрайта', function () {
    $out = HtmlSanitizer::sanitize(
        '<svg viewBox="0 0 24 24" width="18" height="18"><use href="#tabler-printer"></use></svg>'
    );
    assert_contains('viewBox="0 0 24 24"', $out);
    assert_not_contains('viewbox=', $out);
    assert_contains('href="#tabler-printer"', $out);
});

test('HtmlSanitizer: <use> ведёт только внутрь документа', function () {
    assert_not_contains('href', HtmlSanitizer::sanitize('<svg><use href="https://evil.example/x.svg#a"></use></svg>'));
    assert_not_contains('href', HtmlSanitizer::sanitize('<svg><use href="javascript:alert(1)"></use></svg>'));
});

test('HtmlSanitizer: градиенты переживают очистку', function () {
    $out = HtmlSanitizer::sanitize(
        '<svg viewBox="0 0 10 10"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1" gradientUnits="userSpaceOnUse">'
        . '<stop offset="0" stop-color="#f00"></stop></linearGradient></defs><rect width="10" height="10" fill="url(#g)"></rect></svg>'
    );
    assert_contains('<linearGradient', $out);
    assert_contains('gradientUnits="userSpaceOnUse"', $out);
    assert_contains('stop-color="#f00"', $out);
    assert_contains('fill="url(#g)"', $out);
});

test('HtmlSanitizer: расширение SVG не открыло дорогу скриптам', function () {
    $out = HtmlSanitizer::sanitize('<svg onload="alert(1)"><script>alert(2)</script><path d="M0 0"/></svg>');
    assert_not_contains('onload', $out);
    assert_not_contains('<script', $out);
    assert_contains('<path d="M0 0">', $out);
});

test('HtmlSanitizer: слово viewbox в тексте не подменяется', function () {
    assert_contains('слово viewbox в тексте', HtmlSanitizer::sanitize('<p>слово viewbox в тексте</p>'));
});
