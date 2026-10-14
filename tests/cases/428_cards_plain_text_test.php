<?php

declare(strict_types=1);

use App\Core\BlockData\CardsGridBlockNormalizer;
use App\Core\BlockRenderer;
use App\Core\TextProcessor;

// Поле карточки — простой текст. HTML, приехавший в него из импорта или из
// вставки, печатался на странице кодом: «<p>&ldquo;…</p>».
test('Карточки: HTML в тексте карточки становится текстом — и при сохранении, и на выводе', function (): void {
    $dirty = '<p>&ldquo;O&lsquo;zbekiston &mdash; 2030&rdquo; strategiyasi. 30&nbsp;ta</p>';

    assert_same('a < 5 % va b > 3', TextProcessor::plainText('a < 5 % va b > 3'));
    assert_same('Tom & Jerry', TextProcessor::plainText('Tom &amp; Jerry'));

    $saved = CardsGridBlockNormalizer::normalize(['items' => [['title' => '<b>Adliya</b>', 'text' => $dirty]]], 'uz');
    assert_same('Adliya', $saved['items'][0]['title']);
    assert_not_contains('<p>', $saved['items'][0]['text']);
    assert_not_contains('&ldquo;', $saved['items'][0]['text']);

    // Запись, сохранённая до очистки на входе, выводится уже чистой.
    $html = (string) BlockRenderer::render([
        'id' => 905,
        'type' => 'cards_grid',
        'data' => json_encode(['variant' => 'icon', 'items' => [['title' => 'Adliya', 'text' => $dirty]]], JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ])['html'];
    assert_contains('“O‘zbekiston — 2030” strategiyasi.', $html);
    assert_not_contains('&lt;p&gt;', $html);
    assert_not_contains('&amp;ldquo;', $html);
});
