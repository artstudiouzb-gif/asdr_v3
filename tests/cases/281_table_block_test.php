<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\TableRows;

test('Таблица: ряды разбираются построчно и выравниваются по самому длинному', function (): void {
    $rows = TableRows::parse("Показатель | 2024 | 2025\n\nВВП | 5,6 %\n Экспорт | 12 | 14 | 16 ");

    assert_same(3, count($rows));
    // Пустая строка — приём набора, а не пустой ряд: полоса посреди таблицы
    // читалась бы как ошибка вёрстки.
    assert_same(['Показатель', '2024', '2025', ''], $rows[0]);
    // Короткий ряд добивается пустыми ячейками, иначе таблица разъезжается.
    assert_same(['ВВП', '5,6 %', '', ''], $rows[1]);
    // Лишняя ячейка не отбрасывается: набранные данные молча теряться не должны.
    assert_same(['Экспорт', '12', '14', '16'], $rows[2]);
    assert_same(4, TableRows::width($rows));

    assert_same([], TableRows::parse("   \n\n "));
    assert_same(0, TableRows::width([]));
});

test('Таблица: шапка размечена scope, широкая — прокручивается своей областью', function (): void {
    $data = BlockFieldSchema::normalize('table', [
        'title_field' => 'Ключевые показатели',
        'rows' => "Показатель | 2024\nВВП | 5,6 %",
        'header_row' => '1',
        'header_col' => '1',
        'variant' => 'striped',
        'density' => 'compact',
    ], 'ru');

    $html = BlockRenderer::render([
        'id' => 42,
        'type' => 'table',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ])['html'];

    // Без scope диктор не объявляет заголовок при переходе по ячейкам, и
    // таблица превращается в набор чисел без смысла.
    assert_contains('<th scope="col">Показатель</th>', $html);
    assert_contains('<th scope="row">ВВП</th>', $html);
    assert_contains('<td>5,6 %</td>', $html);
    assert_contains('block-table__grid--striped', $html);
    assert_contains('block-table__grid--compact', $html);

    // Прокрутка всей страницы по горизонтали ломает шапку и якорную навигацию,
    // поэтому область прокрутки своя — и до неё можно добраться с клавиатуры.
    assert_contains('role="region"', $html);
    assert_contains('tabindex="0"', $html);
    assert_contains('aria-label="Ключевые показатели"', $html);

    // Ячейка — простой текст: HTML в неё не допускается, как и в заголовок.
    $unsafe = BlockFieldSchema::normalize('table', ['rows' => '<b>жирный</b> | <script>alert(1)</script>'], 'ru');
    $unsafeHtml = BlockRenderer::render([
        'id' => 43, 'type' => 'table',
        'data' => json_encode($unsafe, JSON_UNESCAPED_UNICODE), 'custom_css' => '',
    ])['html'];
    assert_not_contains('<b>', $unsafeHtml);
    assert_not_contains('<script>', $unsafeHtml);
});

test('Таблица: без шапки первая строка остаётся обычным рядом', function (): void {
    $data = BlockFieldSchema::normalize('table', [
        'rows' => "Понедельник | 09:00 – 18:00\nВторник | 09:00 – 18:00",
        'variant' => 'lines',
    ], 'ru');
    assert_false((bool) $data['header_row']);

    $html = BlockRenderer::render([
        'id' => 44, 'type' => 'table',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'custom_css' => '',
    ])['html'];

    assert_not_contains('<thead>', $html);
    assert_contains('<td>Понедельник</td>', $html);
});

test('Таблица: каждый вариант и плотность имеют правило', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/table.css');

    foreach (['lines', 'striped', 'bordered'] as $variant) {
        assert_contains('.block-table__grid--' . $variant, $css);
    }
    assert_contains('.block-table__grid--compact', $css);
    assert_contains('overflow-x: auto', $css);
});
