<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer;
use App\Core\BlockRenderer;
use App\Core\SectionHead;

/*
 * Подзаголовок над заголовком и выравнивание шапки секции — настройки
 * оформления любого блока с шапкой (`_head_kicker`, `_head_kicker_style`,
 * `_head_align`). Применяются к готовой разметке (SectionHead::decorate),
 * поэтому сторожить нужно две вещи: вывод и то, что форма предлагает
 * настройку только там, где шапка действительно есть.
 */

function section_head_render(string $type, array $data): string
{
    return BlockRenderer::render(['id' => 433, 'type' => $type, 'custom_css' => null, 'data' => json_encode($data)])['html'];
}

test('Шапка секции: подзаголовок встаёт перед заголовком, выравнивание — классом', function (): void {
    $items = [['icon_svg' => 'target', 'title' => 'Цели', 'text' => 'Мониторинг']];
    $plain = section_head_render('cards_grid', ['title' => 'Направления', 'items' => $items]);
    assert_not_contains('section-head__kicker', $plain, 'без настройки вид прежний');
    assert_not_contains('section-head--align-center', $plain);

    $html = section_head_render('cards_grid', [
        'title' => 'Направления', 'items' => $items,
        '_head_kicker' => 'Что <мы> делаем', '_head_kicker_style' => 'plain', '_head_align' => 'center',
    ]);
    assert_contains('<p class="section-head__kicker section-head__kicker--plain">Что &lt;мы&gt; делаем</p><h2 class="section-head__title', $html, 'перед заголовком и экранирован');
    assert_contains('class="section-head section-head--align-center', $html);
    assert_same(1, substr_count($html, '<p class="section-head__kicker'), 'только первая шапка блока');

    // Шапка своей разметкой, без обёртки __copy (карточки с фото).
    $photo = section_head_render('cards_grid', ['title' => 'Проекты', 'variant' => 'image', 'items' => [['title' => 'A', 'image' => '/uploads/public/a.jpg']], '_head_kicker' => 'Проекты']);
    assert_contains('section-head__kicker--line">Проекты</p><h2', $photo);

    // Без заголовка подзаголовку не к чему относиться.
    assert_same('<div class="section-head"><div class="x"></div></div>', SectionHead::decorate('<div class="section-head"><div class="x"></div></div>', ['_head_kicker' => 'A']));
});

test('Шапка секции: нормализатор пишет ключи только при выборе', function (): void {
    $none = BlockPresentationNormalizer::normalize([]);
    assert_false(isset($none['_head_kicker']) || isset($none['_head_align']), 'у собранных блоков ключей нет');
    $set = BlockPresentationNormalizer::normalize(['head_kicker' => '  Итоги  ', 'head_kicker_style' => 'bogus', 'head_align' => 'center']);
    assert_same('Итоги', $set['_head_kicker']);
    assert_same('line', $set['_head_kicker_style'], 'чужой вид — умолчание');
    assert_same('center', $set['_head_align']);
    assert_false(isset(BlockPresentationNormalizer::normalize(['head_align' => 'right'])['_head_align']), 'вправо не предлагается');
});

test('Шапка секции: настройка предлагается ровно тем типам, у которых шапка есть', function (): void {
    foreach (glob(APP_ROOT . '/templates/blocks/*.php') ?: [] as $file) {
        $type = basename($file, '.php');
        $hasHead = str_contains((string) file_get_contents($file), 'section-head') || str_contains((string) file_get_contents($file), 'SectionHead::render');
        assert_same($hasHead, in_array($type, SectionHead::DECORATED_TYPES, true), $type . ': список SectionHead::DECORATED_TYPES разошёлся с шаблоном');
    }
    $form = block_editor_markup();
    assert_contains('name="head_kicker"', $form);
    assert_contains('SectionHead::DECORATED_TYPES', $form, 'форма спрашивает тот же список');
});
