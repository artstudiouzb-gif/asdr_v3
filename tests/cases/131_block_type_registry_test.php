<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\BlockSamples;
use App\Core\BlockTypeRegistry;

/**
 * Канонический состав библиотеки блоков. Задан явным списком, а не числом:
 * при добавлении или удалении типа в diff видно, что именно изменилось.
 * Редизайн «Redesign frontend block system for clean installs» свёл 38 типов
 * к 31, объединив banner/cta_band/feature_band в `cta`,
 * gallery/media_materials в `media_gallery`,
 * categories_grid/image_cards в `cards_grid`.
 */
const EXPECTED_BLOCK_TYPES = [
    'text', 'html', 'cta', 'advantages',
    'slider', 'form', 'columns', 'tabs', 'testimonials',
    'counters', 'team_list', 'projects_list', 'news_latest',
    'partners', 'subscribe', 'faq', 'contact_cards',
    'hero', 'cards_grid', 'media_gallery', 'news_feature',
    'person_cards', 'timeline', 'news_docs', 'person_profile',
    'bio_education', 'anchor_nav', 'stages', 'text_image',
    'docs_list', 'map_point', 'org_structure', 'leader_card', 'icon_text',
    'collage', 'table', 'image', 'embed', 'chart', 'divider', 'buttons',
];

test('Реестр блоков: все источники используют одинаковый набор типов', function () {
    $types = BlockTypeRegistry::types();

    assert_same(EXPECTED_BLOCK_TYPES, $types);
    assert_same($types, array_keys(BlockTypeRegistry::TYPE_LABELS));
    assert_same($types, array_keys(BlockTypeRegistry::editorLabels()));

    $sampleTypes = array_keys(BlockSamples::all());
    sort($types);
    sort($sampleTypes);
    assert_same($types, $sampleTypes);
});

test('Реестр блоков: совместимые фасады рендера не изменились', function () {
    assert_same(BlockTypeRegistry::defaults(), BlockRenderer::defaults());
    assert_same(BlockTypeRegistry::TYPE_LABELS, BlockRenderer::TYPE_LABELS);
    assert_same(
        BlockTypeRegistry::defaultsFor('hero'),
        BlockRenderer::defaultsFor('hero')
    );
    assert_same([], BlockTypeRegistry::defaultsFor('unknown'));
});

test('Реестр блоков: каждому обычному типу соответствует шаблон', function () {
    foreach (BlockTypeRegistry::types() as $type) {
        $template = BlockTypeRegistry::templateFile($type);
        // Контейнеры (колонки, вкладки) рендерятся программно: их содержимое —
        // вложенные блоки, шаблона у них нет.
        if (BlockTypeRegistry::isContainer($type)) {
            assert_same(null, $template);
            continue;
        }

        assert_true($template !== null && is_file($template), "{$type}: шаблон блока не найден");
    }

    assert_same(null, BlockTypeRegistry::templateFile('unknown'));
});

test('Реестр блоков: форма и контроллер не содержат собственных списков типов', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    // Список типов для конструктора живёт в общем партиале: его подключают и
    // форма страницы, и форма проекта.
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/_block_editor.php');

    assert_not_contains('private const TYPES', $controller);
    assert_contains('BlockTypeRegistry::has($type)', $controller);
    assert_contains('BlockTypeRegistry::editorLabels()', $form);
});
