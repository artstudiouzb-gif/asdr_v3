<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\BlockTypeRegistry;

/*
 * Ссылка блока проверяется и на выводе. Загруженный файл шаблона страницы
 * сверяет у данных только ключи (PageTemplateFile::data), значения остаются
 * как есть, — и `javascript:` в href «Изображения», «Оглавления», «Кнопок» и
 * «Коллажа» доходил до публичной страницы: чужой код по клику посетителя.
 */

/** @param array<string, mixed> $data */
function link_guard_render(string $type, array $data): string
{
    $data += BlockTypeRegistry::defaultsFor($type);
    ob_start();
    try {
        $result = BlockRenderer::render([
            'id' => 990001,
            'type' => $type,
            'data' => json_encode($data),
            'page_id' => 1,
            'sort_order' => 1,
            'parent_block_id' => null,
            'column_index' => 0,
        ]);
    } finally {
        $echoed = (string) ob_get_clean();
    }

    return $echoed . (string) ($result['html'] ?? '');
}

test('Схема: поле-ссылка приводится и на выводе', function (): void {
    $applied = BlockFieldSchema::apply('image', ['image' => '/uploads/public/a.jpg', 'link' => 'javascript:alert(1)']);
    assert_same('', $applied['link'], 'javascript: снят');
    $kept = BlockFieldSchema::apply('image', ['image' => '/uploads/public/a.jpg', 'link' => '/about']);
    assert_same('/about', $kept['link'], 'обычная ссылка остаётся');
});

test('Блоки со ссылками в повторяющихся полях не выводят javascript:', function (): void {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
    }
    $js = 'javascript:alert(document.domain)';
    $cases = [
        'image' => ['image' => '/uploads/public/a.jpg', 'link' => $js],
        'anchor_nav' => ['items' => [['label' => 'Раздел', 'url' => $js], ['label' => 'Контакты', 'url' => '#contacts']]],
        'buttons' => ['items' => [
            ['label' => 'Плохая', 'url' => $js, 'style' => 'primary', 'icon_svg' => '', 'new_tab' => false],
            ['label' => 'Хорошая', 'url' => '/forms', 'style' => 'primary', 'icon_svg' => '', 'new_tab' => false],
        ]],
    ];
    foreach ($cases as $type => $data) {
        $html = link_guard_render($type, $data);
        assert_not_contains('javascript:', $html, $type);
    }
    assert_contains('href="#contacts"', link_guard_render('anchor_nav', $cases['anchor_nav']), 'безопасный пункт остаётся');
    assert_contains('href="/forms"', link_guard_render('buttons', $cases['buttons']), 'безопасная кнопка остаётся');

    // Коллаж: элемент со ссылкой проверяется в самом шаблоне.
    $collage = (string) file_get_contents(APP_ROOT . '/templates/blocks/collage.php');
    assert_contains('UrlGuard::isSafeLink($link)', $collage);
});
