<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Блок FAQ рендерит нативный аккордеон details/summary (группа 6)', function () {
    $out = BlockRenderer::render([
        'id' => 1, 'type' => 'faq', 'custom_css' => null,
        'data' => json_encode([
            'title' => 'Частые вопросы',
            'items' => [
                ['question' => 'Как заказать?', 'answer' => '<p>Через форму на сайте.</p>'],
                ['question' => 'Сроки?', 'answer' => 'От 3 дней.'],
            ],
        ]),
    ])['html'];

    assert_contains('block-faq', $out);
    // Без закрывающего «>»: у <details> появились id и data-атрибуты поиска,
    // проверять надо нативный элемент с классом, а не точную строку тега.
    assert_contains('<details class="faq-item"', $out);
    assert_contains('<summary', $out);
    assert_contains('Как заказать?', $out);
    assert_contains('Через форму на сайте.', $out);
    // Два вопроса → два <details>.
    assert_same(2, substr_count($out, '<details'));
});
