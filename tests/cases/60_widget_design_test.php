<?php

declare(strict_types=1);

use App\Core\WidgetRenderer;

// Панель оформления виджетов: нормализация _design и классы в рендере.

test('WidgetRenderer::normalizeDesign: дефолты и отбраковка мусора', function () {
    $d = WidgetRenderer::normalizeDesign([]);
    assert_same('default', $d['style']);
    assert_same('normal', $d['pad']);
    assert_false($d['accent']);

    $d = WidgetRenderer::normalizeDesign(['_design' => ['style' => 'navy', 'pad' => 'spacious', 'accent' => 1]]);
    assert_same('navy', $d['style']);
    assert_same('spacious', $d['pad']);
    assert_true($d['accent']);

    $d = WidgetRenderer::normalizeDesign(['_design' => ['style' => 'evil', 'pad' => 'huge']]);
    assert_same('default', $d['style']);
    assert_same('normal', $d['pad']);
});

test('WidgetRenderer::render добавляет классы оформления', function () {
    $widget = [
        'id' => 77,
        'type' => 'custom_html',
        'title' => 'Баннер',
        'data' => json_encode([
            'html' => '<p>Привет</p>',
            '_design' => ['style' => 'card', 'pad' => 'compact', 'accent' => true],
        ], JSON_UNESCAPED_UNICODE),
    ];
    $html = WidgetRenderer::render($widget, 'ru');
    assert_contains('widget--style-card', $html);
    assert_contains('widget--pad-compact', $html);
    assert_contains('widget--accent', $html);
    assert_contains('widget--custom_html', $html);
    assert_contains('<h3 class="widget__title">Баннер</h3>', $html);
    assert_not_contains('<h2 class="widget__title">', $html);
});

test('WidgetRenderer::render без _design — без классов оформления', function () {
    $widget = [
        'id' => 78,
        'type' => 'custom_html',
        'title' => null,
        'data' => json_encode(['html' => '<p>x</p>']),
    ];
    $html = WidgetRenderer::render($widget, 'ru');
    assert_not_contains('widget--style-', $html);
    assert_not_contains('widget--pad-', $html);
    assert_not_contains('widget--accent', $html);
});
