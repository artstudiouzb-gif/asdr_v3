<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer as Presentation;
use App\Core\BlockRenderer;

test('Новый блок создаётся без появления при прокрутке', function (): void {
    // Появление — решение редактора. Умолчание «у всех, кроме первого и
    // вложенного» давало на демо-главной пять въезжающих секций из шести —
    // признак шаблонной страницы (навык frontend-design, DESIGN_PLAN 4.3).
    assert_same([], Presentation::newBlockPresentation(false, false));
    assert_same([], Presentation::newBlockPresentation(true, false));
    assert_same([], Presentation::newBlockPresentation(false, true));
});

test('Появление, выбранное редактором, доезжает до вывода', function (): void {
    // Форма и рендерер читают _reveal своей формой записи. Разъедется shape —
    // блок сохранится «с анимацией», а на странице её не будет.
    $data = ['title' => 'Проба', 'text' => '<p>Текст</p>', '_reveal' => ['enabled' => true, 'type' => 'fade']];
    $out = BlockRenderer::render([
        'id' => 1,
        'type' => 'text',
        'title' => 'Проба',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
    ]);
    $html = (string) ($out['html'] ?? '');
    assert_contains('data-reveal', $html);
    assert_contains('data-reveal-type="fade"', $html);
});

test('Редактор добавляет блок с этим умолчанием, а не мимо него', function (): void {
    // Умолчание объявлено один раз; контроллер обязан звать его, иначе список
    // исключений разъедется с описанием.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    assert_contains('newBlockPresentation(', $controller);
    assert_contains('Block::forPage($pageId, $lang) === []', $controller);
});

test('Скрипт для готовых страниц пропускает первый блок, обложку и чужой выбор', function (): void {
    // Страницы, собранные до появления умолчания, догоняются скриптом. Его
    // исключения обязаны совпадать с исключениями умолчания, иначе задним
    // числом заанимируется первый экран.
    $script = (string) file_get_contents(APP_ROOT . '/scripts/enable_block_reveal.php');
    assert_contains('--dry-run', $script);
    assert_contains('parent_block_id IS NULL', $script);
    assert_contains('первый блок страницы', $script);
    assert_contains("'hero'", $script);
    assert_contains('анимация уже выбрана', $script);
    assert_contains('clearPageCache', $script);
});
