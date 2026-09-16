<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\CollageBlockNormalizer;
use App\Core\CollageLayout;

/*
 * Типы сборки коллажа.
 *
 * В свободной сетке редактор задаёт четыре числа на каждый элемент, и увидеть
 * результат можно только сохранив блок. Пресет считает и сетку, и места по
 * числу элементов — поэтому он обязан отдавать холст, который принимает схема
 * полей: «Колонок» это выбор из 4/6/8/12, «Строк» — число от 2 до 8. Своя
 * сетка мимо этого набора даёт тихий отказ — вывод прогоняет данные через
 * схему, та заменяет чужое значение умолчанием, и композиция разъезжается.
 * Замерено при разработке: «Ряд» считал 3×1, а на выводе получалось 6×2.
 */

test('Раскладка укладывается в холст, который принимает схема', function (): void {
    $allowedColumns = [4, 6, 8, 12];
    $problems = [];

    foreach (array_keys(CollageLayout::LAYOUTS) as $layout) {
        if (!CollageLayout::isPreset($layout)) {
            continue;
        }

        for ($count = 1; $count <= 12; $count++) {
            $placed = CollageLayout::place($layout, $count);
            $where = $layout . ' на ' . $count . ' элементов';

            if (!in_array($placed['columns'], $allowedColumns, true)) {
                $problems[] = $where . ': колонок ' . $placed['columns'];
            }
            if ($placed['rows'] < 2 || $placed['rows'] > 8) {
                $problems[] = $where . ': строк ' . $placed['rows'];
            }
            if (count($placed['cells']) !== $count) {
                $problems[] = $where . ': мест ' . count($placed['cells']);
            }

            foreach ($placed['cells'] as $cell) {
                if ($cell['col'] + $cell['col_span'] - 1 > $placed['columns']) {
                    $problems[] = $where . ': элемент выходит за правый край';
                }
                if ($cell['row'] + $cell['row_span'] - 1 > $placed['rows']) {
                    $problems[] = $where . ': элемент выходит за нижний край';
                }
            }
        }
    }

    assert_same([], array_values(array_unique($problems)), implode('; ', array_unique($problems)));
});

test('Пустых ячеек в композиции не остаётся', function (): void {
    $holes = [];

    foreach (array_keys(CollageLayout::LAYOUTS) as $layout) {
        // У наложения пустое место законно: элементы идут по диагонали, и
        // углы полотна там свободны намеренно — этим оно и отличается от
        // сетки. Остальные раскладки заполняют холст целиком: дыра в ряду
        // читается как незагрузившийся элемент, а не как замысел.
        if (!CollageLayout::isPreset($layout) || $layout === 'stack') {
            continue;
        }

        for ($count = 1; $count <= 12; $count++) {
            $placed = CollageLayout::place($layout, $count);
            $covered = [];
            foreach ($placed['cells'] as $cell) {
                for ($row = $cell['row']; $row < $cell['row'] + $cell['row_span']; $row++) {
                    for ($col = $cell['col']; $col < $cell['col'] + $cell['col_span']; $col++) {
                        $covered[$row . ':' . $col] = true;
                    }
                }
            }
            $cells = $placed['columns'] * $placed['rows'];
            if (count($covered) !== $cells) {
                $holes[] = $layout . ' на ' . $count . ': занято ' . count($covered) . ' из ' . $cells;
            }
        }
    }

    assert_same([], $holes, implode('; ', $holes));
});

test('Свободная сетка остаётся умолчанием и мест не пересчитывает', function (): void {
    assert_same(CollageLayout::FREE, BlockFieldSchema::normalize('collage', [], 'ru')['layout']);
    assert_true(!CollageLayout::isPreset(CollageLayout::FREE));

    // Номера ячеек, заданные редактором, у свободной сетки сохраняются как
    // есть: у собранных раньше страниц вид не меняется.
    $data = CollageBlockNormalizer::normalize([
        'layout' => 'free',
        'columns' => 6,
        'rows' => 4,
        'items' => [
            ['type' => 'photo', 'image' => '/uploads/public/a.jpg', 'col' => 2, 'col_span' => 3, 'row' => 2, 'row_span' => 2],
        ],
    ]);

    assert_same(6, $data['columns']);
    assert_same(4, $data['rows']);
    assert_same([2, 3, 2, 2], [
        $data['items'][0]['col'],
        $data['items'][0]['col_span'],
        $data['items'][0]['row'],
        $data['items'][0]['row_span'],
    ]);
});

test('Пресет считает места сам, и пустой элемент их не сдвигает', function (): void {
    $data = CollageBlockNormalizer::normalize([
        'layout' => 'hero',
        // Числа из формы у пресета ничего не значат: их задаёт раскладка.
        'columns' => 12,
        'rows' => 8,
        'items' => [
            ['type' => 'photo', 'image' => '/uploads/public/a.jpg', 'col' => 5, 'row' => 3],
            // Пустой элемент выпадает при отборе. Если бы места считались до
            // него, на его месте осталась бы дыра.
            ['type' => 'photo', 'image' => ''],
            ['type' => 'stat', 'value' => '128', 'label' => 'проектов'],
            ['type' => 'stat', 'value' => '12', 'label' => 'районов'],
        ],
    ]);

    assert_same(3, count($data['items']), 'пустой элемент в композицию не попадает');
    assert_same(CollageLayout::place('hero', 3)['columns'], $data['columns']);
    assert_same(CollageLayout::place('hero', 3)['rows'], $data['rows']);

    $expected = CollageLayout::place('hero', 3)['cells'];
    foreach ($data['items'] as $i => $item) {
        assert_same($expected[$i]['col'], $item['col'], 'колонка элемента ' . $i);
        assert_same($expected[$i]['col_span'], $item['col_span'], 'ширина элемента ' . $i);
        assert_same($expected[$i]['row'], $item['row'], 'строка элемента ' . $i);
        assert_same($expected[$i]['row_span'], $item['row_span'], 'высота элемента ' . $i);
    }
});

test('Тип сборки виден редактору плитками и скрывает ручные места', function (): void {
    $editor = block_editor_markup();

    assert_contains('name="layout"', $editor, 'поле типа сборки есть в редакторе');
    foreach (CollageLayout::LAYOUTS as $key => $label) {
        assert_contains($label, $editor, 'подпись типа сборки: ' . $label);
    }

    // Поля размещения прячет скрипт панели, а на их месте печатается строка о
    // том, кто теперь ими распоряжается: исчезнувшее без объяснения поле —
    // тот же тихий отказ, что и настройка без последствий.
    assert_contains('data-collage-place', $editor, 'у полей размещения есть зацепка для скрипта');
    assert_contains('data-collage-auto', $editor, 'редактору объясняется, кто считает места');

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains('data-collage-place', $js, 'скрипт панели прячет ручные места');
    assert_contains("name === 'layout'", $js, 'скрипт следит за сменой типа сборки');
});

test('У каждого типа сборки своя плитка с рисунком', function (): void {
    $field = BlockFieldSchema::fields('collage')['layout'] ?? null;
    assert_true($field !== null, 'поле описано схемой');

    $variants = $field->variants ?? [];
    assert_same(array_keys(CollageLayout::LAYOUTS), array_keys($variants), 'рисунок есть у каждого типа');

    foreach ($variants as $key => $variant) {
        assert_true(
            \App\Core\VariantPreview::isKnown((string) $variant[0]),
            'рисунок «' . $variant[0] . '» неизвестен VariantPreview (тип ' . $key . ')'
        );
        assert_true(trim((string) ($variant[1] ?? '')) !== '', 'у типа ' . $key . ' нет пояснения');
    }
});
