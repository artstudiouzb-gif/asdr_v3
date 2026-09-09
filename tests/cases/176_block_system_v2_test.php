<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\BlockSamples;
use App\Core\BlockTypeRegistry;
use App\Core\DocumentPresenter;
use App\Core\MapEmbedUrl;
use App\Core\MediaPosition;

test('Система блоков v2 не содержит удалённых дубликатов', function (): void {
    $removed = [
        'banner',
        'cta_band',
        'feature_band',
        'categories_grid',
        'image_cards',
        'media_materials',
        'gallery',
    ];

    foreach ($removed as $type) {
        assert_false(BlockTypeRegistry::has($type), "{$type} не должен оставаться в реестре");
        assert_false(is_file(APP_ROOT . '/templates/blocks/' . $type . '.php'), "{$type}: старый шаблон не удалён");
    }

    $registered = BlockTypeRegistry::types();
    $sampled = array_keys(BlockSamples::all());
    sort($registered);
    sort($sampled);
    assert_same($registered, $sampled, 'каждый новый тип должен иметь образец, лишних образцов быть не должно');
});

test('Варианты объединённых блоков сохраняют все нужные макеты', function (): void {
    $ctaBand = BlockRenderer::render([
        'id' => 1761,
        'type' => 'cta',
        'data' => json_encode(['variant' => 'band', 'title' => 'Свяжитесь с нами']),
    ]);
    assert_contains('block-ctaband', $ctaBand['html']);

    $ctaMedia = BlockRenderer::render([
        'id' => 1762,
        'type' => 'cta',
        'data' => json_encode(['variant' => 'media-light', 'title' => 'Приём', 'image' => '/uploads/public/cta.jpg']),
    ]);
    assert_contains('block-banner--light', $ctaMedia['html']);
    assert_contains('media-position--center-center', $ctaMedia['html']);

    $compactCards = BlockRenderer::render([
        'id' => 1763,
        'type' => 'cards_grid',
        'data' => json_encode(['variant' => 'compact', 'items' => [['title' => 'Новости', 'url' => '/news']]]),
    ]);
    assert_contains('cat-tile is-active', $compactCards['html']);

    $imageCards = BlockRenderer::render([
        'id' => 1764,
        'type' => 'cards_grid',
        'data' => json_encode(['variant' => 'image', 'items' => [['title' => 'Проект', 'image' => '/uploads/public/project.jpg']]]),
    ]);
    assert_contains('imgcard', $imageCards['html']);

    $advantageBand = BlockRenderer::render([
        'id' => 1765,
        'type' => 'advantages',
        'data' => json_encode(['variant' => 'band', 'items' => [['title' => 'Открытость', 'text' => 'Описание']]]),
    ]);
    assert_contains('featband__item', $advantageBand['html']);

    $editorialText = BlockRenderer::render([
        'id' => 1769,
        'type' => 'text',
        'data' => json_encode([
            'variant' => 'system',
            'title' => 'Система',
            'content' => '<p>Вводный текст.</p>',
            'aside_title' => 'Принципы',
            'items' => [['icon_svg' => 'target', 'title' => 'Цель']],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    assert_contains('block-text--system', $editorialText['html']);
    assert_contains('block-text__system-list', $editorialText['html']);

    $history = BlockRenderer::render([
        'id' => 1770,
        'type' => 'stages',
        'data' => json_encode([
            'variant' => 'history',
            'title' => 'История организации',
            'description' => '<p>Ключевые этапы развития.</p>',
            'items' => [['year' => '2025', 'title' => 'Новая система']],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    assert_contains('block-stages--history', $history['html']);
    assert_contains('section-head__description', $history['html']);
    assert_contains('Ключевые этапы развития.', $history['html']);

    $editorialActs = BlockRenderer::render([
        'id' => 1771,
        'type' => 'docs_list',
        'data' => json_encode(['variant' => 'acts-editorial', 'items' => [['title' => 'Указ', 'number' => 'ПФ-1', 'date' => '2026']]], JSON_UNESCAPED_UNICODE),
    ]);
    assert_contains('block-docslist--acts-editorial', $editorialActs['html']);
    assert_contains('feature-card act-card', $editorialActs['html']);
    assert_contains('act-card__editorial-head', $editorialActs['html']);
});

test('Документы определяют формат, действие и поддерживают поиск', function (): void {
    $pdf = DocumentPresenter::prepare(['title' => 'Стратегия', 'url' => '/uploads/public/strategy.pdf']);
    assert_same('pdf', $pdf['extension']);
    assert_same('file-type-pdf', $pdf['icon']);
    assert_true($pdf['direct_file']);
    assert_contains('PDF', $pdf['meta']);

    $page = DocumentPresenter::prepare(['title' => 'Раздел', 'url' => '/documents']);
    assert_same('other', $page['extension']);
    assert_false($page['direct_file']);

    $documents = [];
    foreach (['pdf', 'docx', 'xlsx', 'pptx'] as $extension) {
        $documents[] = ['title' => strtoupper($extension), 'url' => '/uploads/public/file.' . $extension];
    }
    $rendered = BlockRenderer::render([
        'id' => 1766,
        'type' => 'docs_list',
        'data' => json_encode(['variant' => 'links', 'search_enabled' => true, 'items' => $documents]),
    ]);
    assert_contains('data-document-query', $rendered['html']);
    assert_contains('data-document-kind-filter', $rendered['html']);
    assert_contains('doc-card--compact', $rendered['html']);
});

test('FAQ, карта и кадрирование имеют безопасные интерактивные режимы', function (): void {
    $items = [];
    for ($index = 1; $index <= 4; $index++) {
        $items[] = [
            'question' => 'Вопрос ' . $index,
            'answer' => 'Ответ ' . $index,
            'category' => $index % 2 === 0 ? 'Услуги' : 'Общее',
        ];
    }
    $faq = BlockRenderer::render([
        'id' => 1767,
        'type' => 'faq',
        'data' => json_encode(['search_enabled' => true, 'single_open' => true, 'items' => $items]),
    ]);
    assert_contains('data-faq-single', $faq['html']);
    assert_contains('data-faq-query', $faq['html']);
    assert_contains('id="faq-1767-1"', $faq['html']);

    $map = BlockRenderer::render([
        'id' => 1768,
        'type' => 'map_point',
        'data' => json_encode(['embed_url' => 'https://maps.google.com/maps?q=41.31,69.24', 'load_mode' => 'click']),
    ]);
    assert_contains('data-map-src=', $map['html']);
    assert_not_contains('<iframe', $map['html']);
    assert_same('', MapEmbedUrl::normalize('https://example.com/not-a-map'));

    assert_same('center-center', MediaPosition::normalize('unexpected'));
    assert_same(
        'media-position--right-top media-position-mobile--left-bottom',
        MediaPosition::classes('right-top', 'left-bottom')
    );
});
