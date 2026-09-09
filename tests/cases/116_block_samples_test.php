<?php

declare(strict_types=1);

use App\Core\BlockHints;
use App\Core\BlockRenderer;
use App\Core\BlockSamples;
use App\Core\BlockTypeRegistry;

/**
 * Структурные блоки образца не имеют по своей природе: «Колонки» наполняются
 * дочерними блоками, «Слайдер» — загруженными фото, «Форма» — выбранной
 * формой (её подставляет контроллер, если формы на сайте есть).
 */
const SAMPLE_EXEMPT = ['columns', 'slider', 'form'];

test('Образцы: есть у каждого содержательного типа блока', function () {
    foreach (array_keys(BlockRenderer::defaults()) as $type) {
        if (in_array($type, SAMPLE_EXEMPT, true)) {
            continue;
        }
        assert_true(
            BlockSamples::for($type) !== [],
            "{$type}: нет образца наполнения — редактор добавит блок и увидит пустоту"
        );
    }
});

test('Образцы: блок с образцом виден на странице', function () {
    ensure_test_db();
    foreach (array_keys(BlockRenderer::defaults()) as $i => $type) {
        if (in_array($type, SAMPLE_EXEMPT, true)) {
            continue;
        }
        $data = array_merge(BlockRenderer::defaultsFor($type), BlockSamples::for($type));
        $rendered = BlockRenderer::render([
            'id' => 500 + $i, 'type' => $type, 'custom_css' => '',
            'data' => json_encode($data),
        ]);
        assert_false(
            BlockRenderer::isVisuallyEmpty($rendered['html']),
            "{$type}: с образцом всё равно ничего не показывает"
        );
    }
});

test('Образцы: «Команда» с группировкой — иначе образец предупреждает сам на себя', function () {
    // Проверка подсказок ниже зависит от содержимого базы: как только у
    // сотрудников появляются секторы (а на боевом сайте они есть), образец без
    // группировки начинает вызывать предупреждение. Раньше это всплывало
    // случайно — на повторном прогоне тестов по той же базе.
    $sample = BlockSamples::for('team_list');
    assert_true(!empty($sample['group_by_department']), 'у образца «Команда» включена группировка по секторам');
});

test('Образцы: без разметки в экранируемых полях и без нерабочих полей', function () {
    ensure_test_db();
    foreach (array_keys(BlockRenderer::defaults()) as $i => $type) {
        if (in_array($type, SAMPLE_EXEMPT, true)) {
            continue;
        }
        $data = array_merge(BlockRenderer::defaultsFor($type), BlockSamples::for($type));
        $rendered = BlockRenderer::render([
            'id' => 500 + $i, 'type' => $type, 'custom_css' => '',
            'data' => json_encode($data),
        ]);
        // Теги, показанные как текст, — та же ошибка, что была в сборках.
        assert_false(
            (bool) preg_match('/&lt;\/?[a-z]+[^&]{0,20}&gt;/', $rendered['html']),
            "{$type}: разметка попала в поле, которое экранируется"
        );
        // Образец не должен сам содержать «заполнил, а не работает».
        assert_same([], BlockHints::forBlock($type, $data), "{$type}: образец вызывает предупреждение");
    }
});

test('Новый блок создаётся с образцом', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/BlockController.php');
    // Язык блока передаётся, чтобы ссылки образца вели в раздел того же языка.
    assert_contains('BlockSamples::for($type, $lang)', $src);
    assert_contains('array_merge(BlockTypeRegistry::defaultsFor($type), $sample, $presentation)', $src);
    // Блок формы получает первую существующую форму, иначе он бесполезен.
    assert_contains('FormDef::all()[0] ?? null', $src);
});

test('Партнёр без логотипа показывается названием, а не битой картинкой', function () {
    $rendered = BlockRenderer::render([
        'id' => 590, 'type' => 'partners', 'custom_css' => '',
        'data' => json_encode(['title' => 'Партнёры', 'items' => [
            ['name' => 'Название организации', 'logo' => '', 'url' => ''],
        ]]),
    ]);
    assert_contains('block-partners__name', $rendered['html']);
    assert_contains('Название организации', $rendered['html']);
    assert_not_contains('src=""', $rendered['html'], 'пустой src — битая картинка');

    // С логотипом поведение прежнее.
    $withLogo = BlockRenderer::render([
        'id' => 591, 'type' => 'partners', 'custom_css' => '',
        'data' => json_encode(['title' => 'Партнёры', 'items' => [
            ['name' => 'Организация', 'logo' => '/uploads/public/logo.png', 'url' => ''],
        ]]),
    ]);
    assert_contains('block-partners__logo', $withLogo['html']);
    assert_contains('/uploads/public/logo.png', $withLogo['html']);
});
