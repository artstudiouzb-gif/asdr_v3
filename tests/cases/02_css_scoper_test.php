<?php

declare(strict_types=1);

use App\Core\CssScoper;

test('CssScoper: селекторы префиксуются областью блока', function () {
    $out = CssScoper::scope('.title { color: red; } p { margin: 0; }', '#block-9');
    assert_contains('#block-9 .title', $out);
    assert_contains('#block-9 p', $out);
});

test('CssScoper: стили не «утекают» без префикса', function () {
    $out = CssScoper::scope('h2 { font-size: 20px; }', '#block-42');
    // Каждое правило должно нести префикс; «голого» h2 { быть не должно.
    assert_contains('#block-42 h2', $out);
    assert_false((bool) preg_match('/(^|})\s*h2\s*\{/', $out), 'непрефиксованный h2 просочился');
});

test('CssScoper: @media сохраняется, внутренние селекторы префиксуются', function () {
    $out = CssScoper::scope('@media (max-width: 600px) { .box { display: none; } }', '#block-1');
    assert_contains('@media', $out);
    assert_contains('#block-1 .box', $out);
});

test('CssScoper: забытая скобка не выбрасывает весь CSS блока', function () {
    $out = CssScoper::scope('.a { color: red; } .b { color: blue', '#block-7');
    assert_contains('#block-7 .a', $out);
    assert_contains('#block-7 .b', $out);
    assert_contains('color: blue', $out);
});

test('CssScoper: незакрытый @media скоупит вложенные правила', function () {
    $out = CssScoper::scope('@media (max-width: 600px) { .box { display: none; }', '#block-8');
    assert_contains('@media', $out);
    assert_contains('#block-8 .box', $out);
});

test('CssScoper: лишняя закрывающая скобка не даёт правилу выйти из области блока', function () {
    $out = CssScoper::scope('.a { color: red; }} body { display: none; }', '#block-3');
    assert_contains('#block-3 .a', $out);
    // Правило после лишней скобки сохраняется, но остаётся внутри блока.
    assert_contains('display: none', $out);
    assert_false((bool) preg_match('/(^|})\s*body\s*\{/', $out), 'непрефиксованный body просочился');
    assert_not_contains('} body', $out);
});

test('CssScoper: вырезает опасные конструкции', function () {
    $out = CssScoper::scope('.x { background: url(javascript:alert(1)); } @import "evil.css";', '#block-1');
    assert_not_contains('javascript:', $out);
    assert_not_contains('@import', $out);
});
