<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\ButtonsBlockNormalizer;
use App\Core\BlockRenderer;
use App\Core\BlockTypeRegistry;

test('Разделитель: hr доезжает до страницы и не считается пустым блоком', function (): void {
    $html = BlockRenderer::render([
        'id' => 91,
        'type' => 'divider',
        'data' => json_encode(BlockFieldSchema::normalize('divider', ['variant' => 'short'], 'ru')),
        'custom_css' => '',
    ])['html'];

    // Блок без текста и без картинки считался бы пустым и на страницу не
    // попадал: hr в списке самодостаточных элементов именно поэтому.
    assert_contains('<hr class="block-divider block-divider--short"', $html);
    assert_false(BlockRenderer::isVisuallyEmpty($html));

    // Высота есть только у пустого места — вешать её больше не на что.
    $space = BlockRenderer::render([
        'id' => 92,
        'type' => 'divider',
        'data' => json_encode(BlockFieldSchema::normalize('divider', ['variant' => 'space', 'size' => 'large'], 'ru')),
        'custom_css' => '',
    ])['html'];
    assert_contains('block-divider--size-large', $space);
    assert_not_contains('block-divider--size-', $html);
});

test('Разделитель: у каждого вида есть правило', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/divider.css');

    foreach (['line', 'short', 'emblem', 'space'] as $variant) {
        assert_contains('.block-divider--' . $variant, $css);
    }
    foreach (['small', 'medium', 'large'] as $size) {
        assert_contains('.block-divider--size-' . $size, $css);
    }
});

test('Кнопки: не больше трёх, безымянная и без адреса отбрасываются', function (): void {
    $data = ButtonsBlockNormalizer::normalize([
        'align' => 'center',
        'items' => [
            ['text' => 'Скачать бланк', 'url' => '/docs', 'style' => 'outline', 'new_tab' => '1'],
            ['text' => '', 'url' => '/a'],
            ['text' => 'Без адреса', 'url' => ''],
            ['text' => 'Опасная', 'url' => 'javascript:alert(1)'],
            ['text' => 'Вторая', 'url' => '/b', 'style' => 'диагональная'],
            ['text' => 'Третья', 'url' => '/c'],
            ['text' => 'Четвёртая', 'url' => '/d'],
        ],
    ], 'ru');

    // Четвёртая кнопка — это уже меню, а не «что сделать дальше».
    assert_same(ButtonsBlockNormalizer::MAX_BUTTONS, count($data['items']));
    assert_same(['Скачать бланк', 'Вторая', 'Третья'], array_column($data['items'], 'text'));
    assert_same('outline', $data['items'][0]['style']);
    // Значение вне набора — подделанная форма, а не «ближайшее допустимое».
    assert_same('primary', $data['items'][1]['style']);
    assert_same('center', $data['align']);
});

test('Кнопки: внешняя вкладка получает rel и предупреждение для диктора', function (): void {
    $html = BlockRenderer::render([
        'id' => 93,
        'type' => 'buttons',
        'data' => json_encode(ButtonsBlockNormalizer::normalize([
            'items' => [
                ['text' => 'Портал услуг', 'url' => 'https://my.gov.uz', 'new_tab' => '1'],
                ['text' => 'Наш раздел', 'url' => '/services'],
            ],
        ], 'ru'), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ])['html'];

    // target без rel — приглашение подменить нашу страницу через window.opener.
    assert_contains('target="_blank" rel="noopener noreferrer"', $html);
    assert_contains('Откроется в новой вкладке', $html);
    // Внутренняя ссылка вкладку не меняет.
    assert_same(1, substr_count($html, 'target="_blank"'));
    // Класса .btn нет намеренно: общее правило темы красит его в navy через
    // !important и не задаёт отступов — три вида слились бы в один.
    assert_not_contains('class="btn ', $html);
    assert_contains('class="block-buttons__btn block-buttons__btn--', $html);
});

test('Аккордеон и цитата — это FAQ и «Отзывы», отдельных блоков-близнецов нет', function (): void {
    $types = BlockTypeRegistry::types();
    assert_true(!in_array('accordion', $types, true), 'аккордеон это FAQ: та же разметка, скрипт и стили');
    assert_true(!in_array('quote', $types, true), 'цитата это «Отзывы» с одной записью');

    // Редактор должен находить их по своему слову, иначе заведёт близнеца сам.
    $labels = BlockTypeRegistry::editorLabels();
    assert_contains('аккордеон', $labels['faq']);
    assert_contains('цитат', $labels['testimonials']);
});
