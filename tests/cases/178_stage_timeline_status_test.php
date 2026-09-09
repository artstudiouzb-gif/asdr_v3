<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Шкала этапов передаёт статус каждому маркеру и соединению', function () {
    $out = BlockRenderer::render([
        'id' => 1781,
        'type' => 'stages',
        'custom_css' => null,
        'data' => json_encode([
            'title' => 'Этапы',
            'items' => [
                ['title' => 'Подготовка', 'status' => 'done'],
                ['title' => 'Реализация', 'status' => 'active'],
                ['title' => 'Результат', 'status' => 'planned'],
            ],
        ]),
    ])['html'];

    assert_contains('stage--done stage--next-active', $out);
    assert_contains('stage--active stage--next-planned', $out);
    assert_contains('stage--planned', $out);
});

test('Хронология поддерживает статусы и совместима со старыми данными', function () {
    $explicit = BlockRenderer::render([
        'id' => 1782,
        'type' => 'timeline',
        'custom_css' => null,
        'data' => json_encode([
            'items' => [
                ['year' => '2024', 'text' => 'Готово', 'status' => 'done'],
                ['year' => '2025', 'text' => 'В работе', 'status' => 'active'],
                ['year' => '2026', 'text' => 'План', 'status' => 'planned'],
            ],
        ]),
    ])['html'];

    assert_contains('timeline-item--done timeline-item--next-active', $explicit);
    assert_contains('timeline-item--active timeline-item--next-planned', $explicit);
    assert_contains('timeline-item--planned', $explicit);

    $legacy = BlockRenderer::render([
        'id' => 1783,
        'type' => 'timeline',
        'custom_css' => null,
        'data' => json_encode([
            'items' => [
                ['year' => '2024', 'text' => 'Первое'],
                ['year' => '2025', 'text' => 'Второе'],
            ],
        ]),
    ])['html'];

    assert_contains('timeline-item--done timeline-item--next-active', $legacy);
    assert_contains('timeline-item--active', $legacy);
});

test('CSS различает завершённые, текущие и будущие участки', function () {
    $css = theme_css();

    assert_contains('.stage--done.stage--next-active::before', $css);
    assert_contains('.timeline-item--done.timeline-item--next-active::after', $css);
    assert_contains('.timeline-item--planned::before', $css);
});
