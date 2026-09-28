<?php

declare(strict_types=1);

use App\Core\BlockConversion;
use App\Core\BlockTypeRegistry;
use App\Core\BlockVersioning;
use App\Core\Database;
use App\Models\Block;
use App\Models\BlockRevision;
use App\Models\Page;

/*
 * Смена типа собранного блока (BlockConversion): переносится набранное, а не
 * только название; перенос «туда и обратно» не меняет переносимых полей;
 * оформление секции остаётся; откат из истории возвращает и тип.
 */

/** @return list<array<string, mixed>> */
function conversion_items(array $data): array
{
    return array_values((array) ($data['items'] ?? []));
}

test('Смена типа: пары описаны один раз и у каждой названо, что не перенесётся', function (): void {
    foreach (BlockConversion::PAIRS as $from => $targets) {
        assert_true(BlockTypeRegistry::has($from), $from);
        foreach ($targets as $to => $lost) {
            assert_true(BlockTypeRegistry::has($to), $to);
            assert_true($lost !== [], $from . ' → ' . $to . ': потери названы, а не умолчаны');
            // Обратная смена тоже есть: пара, которую нельзя отменить сменой
            // типа, держалась бы только на истории версий.
            assert_true(BlockConversion::allowed($to, $from), $to . ' → ' . $from);
        }
    }
    assert_same([], BlockConversion::targets('hero'), 'обложке пары нет');
});

test('Карточки ↔ Контакты ↔ Иконка и текст: туда и обратно без потерь переносимого', function (): void {
    $cards = [
        'title' => 'Контакты',
        'variant' => 'icon',
        'columns' => 3,
        '_bg_mode' => 'color',
        '_bg_color' => '#0f2b46',
        'items' => [
            ['title' => 'Приёмная', 'text' => '+998 71 000-00-00', 'url' => '/priem', 'icon_svg' => 'phone'],
            ['title' => 'Почта', 'text' => 'info@example.uz', 'url' => '', 'icon_svg' => 'mail'],
        ],
    ];

    $contacts = BlockConversion::convert('cards_grid', 'contact_cards', $cards);
    assert_same('Контакты', (string) $contacts['title']);
    assert_same('Приёмная', (string) conversion_items($contacts)[0]['title']);
    assert_same('+998 71 000-00-00', (string) conversion_items($contacts)[0]['lines']);
    assert_same('/priem', (string) conversion_items($contacts)[0]['link_url']);
    assert_same('#0f2b46', (string) $contacts['_bg_color'], 'оформление секции переносится как есть');

    $back = BlockConversion::convert('contact_cards', 'cards_grid', $contacts);
    foreach (conversion_items($cards) as $i => $item) {
        foreach (['title', 'text', 'url', 'icon_svg'] as $key) {
            assert_same($item[$key], conversion_items($back)[$i][$key], 'карточка ' . $i . ': ' . $key);
        }
    }

    $iconText = BlockConversion::convert('cards_grid', 'icon_text', $cards);
    assert_same('Приёмная | +998 71 000-00-00', (string) conversion_items($iconText)[0]['rows']);
    $again = BlockConversion::convert('icon_text', 'cards_grid', $iconText);
    assert_same('Приёмная', (string) conversion_items($again)[0]['title']);
    assert_same('+998 71 000-00-00', (string) conversion_items($again)[0]['text']);

    // Строки «Иконки и текста» переживают путь через карточку дословно.
    // Исходник — как в базе: прошедший нормализатор (и типограф) формы.
    $source = \App\Core\BlockData\IconTextBlockNormalizer::normalize(['title_field' => 'Телефоны', 'items' => [
        ['icon_svg' => 'phone', 'rows' => "Телефон доверия | 1070\nПо вопросам насилия | 1146"],
    ]]);
    $rows = (string) conversion_items($source)[0]['rows'];
    foreach (['cards_grid', 'contact_cards'] as $via) {
        $there = BlockConversion::convert('icon_text', $via, $source);
        $home = BlockConversion::convert($via, 'icon_text', $there);
        assert_same($rows, (string) conversion_items($home)[0]['rows'], 'через ' . $via);
    }
});

test('Настройки вида не переносятся под чужим смыслом', function (): void {
    // «variant» есть у обоих типов, но значения значат разное: совпавшее имя
    // значения перенесло бы не тот вид.
    $iconText = BlockConversion::convert('cards_grid', 'icon_text', ['variant' => 'cards', 'items' => [['title' => 'А']]]);
    $defaults = BlockConversion::convert('cards_grid', 'icon_text', ['items' => [['title' => 'А']]]);
    assert_same($defaults['variant'] ?? null, $iconText['variant'] ?? null);
});

test('Таблица ↔ Диаграмма: строки «Подпись | Значение»', function (): void {
    $chart = BlockConversion::convert('table', 'chart', [
        'title' => 'Показатели',
        'rows' => "Показатель | 2024 | 2025\nЭкспорт | 12 | 14\nМСБ | 54 | 57",
        'header_row' => true,
    ]);
    assert_same("Экспорт | 12\nМСБ | 54", (string) $chart['rows'], 'строка заголовков — не данные, столбцы после второго не переносятся');

    $table = BlockConversion::convert('chart', 'table', ['title' => 'Доли', 'rows' => "Транспорт | 24\nОбразование | 18", 'unit' => '%']);
    assert_same("Транспорт | 24 %\nОбразование | 18 %", (string) $table['rows'], 'единица измерения становится частью значения');
    assert_false((bool) $table['header_row']);
});

test('Смена типа сохраняется с версией, и откат возвращает тип (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $pageId = Page::create([
        'slug' => 'convert-' . bin2hex(random_bytes(4)),
        'title' => 'Convert',
        'status' => 'draft',
        'meta_title' => '',
        'meta_description' => '',
        'layout_type' => 'no_sidebar',
    ]);
    $data = ['title' => 'Контакты', 'items' => [['title' => 'Приёмная', 'text' => '1070', 'url' => '', 'icon_svg' => 'phone']]];
    $blockId = Block::create($pageId, '', 'cards_grid', null, $data, '');

    try {
        $block = Block::findById($blockId);
        BlockVersioning::updateWithSnapshot($block, null, BlockConversion::convert('cards_grid', 'contact_cards', $data), '', null, null, 'contact_cards');

        $converted = Block::findById($blockId);
        assert_same('contact_cards', (string) $converted['type']);
        $revision = BlockRevision::forBlock($blockId)[0];
        assert_same('cards_grid', (string) $revision['type'], 'версия помнит прежний тип');

        // Контроллер отката передаёт тип версии, если он отличается.
        $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
        assert_contains('$restoreType', $controller);
        BlockVersioning::updateWithSnapshot($converted, null, json_decode((string) $revision['data'], true), '', null, null, (string) $revision['type']);
        $restored = Block::findById($blockId);
        assert_same('cards_grid', (string) $restored['type']);
        assert_same('Приёмная', (string) json_decode((string) $restored['data'], true)['items'][0]['title']);
    } finally {
        $pdo->prepare('DELETE FROM blocks WHERE page_id = ?')->execute([$pageId]);
        $pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$pageId]);
    }
});

test('Форма блока предлагает смену типа только совместимым', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    assert_contains('BlockConversion::targets($type)', $form);
    assert_contains('/convert"', $form);
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/blocks/{id}/convert'", $routes);
});

test('«Контакты»: ссылка без подписи названа подсказкой', function (): void {
    $contacts = BlockConversion::convert('cards_grid', 'contact_cards', ['items' => [['title' => 'Приёмная', 'text' => '1070', 'url' => '/priem']]]);
    assert_same('/priem', (string) conversion_items($contacts)[0]['link_url'], 'адрес сохраняется');
    $hints = implode(' ', \App\Core\BlockHints::forBlock('contact_cards', $contacts));
    assert_contains('ссылка указана без подписи', $hints);
});
