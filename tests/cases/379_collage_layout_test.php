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

/*
 * Типы элементов. Цитата и показатель нужны в композиции не меньше
 * фотографии: коллаж на госсайте — это «кадр, число и слово руководителя»,
 * и до сих пор слово приходилось ставить отдельным блоком под коллажем.
 */
test('Коллаж умеет цитату, а показатель называется показателем', function (): void {
    assert_true(in_array('quote', CollageBlockNormalizer::TYPES, true), 'тип цитаты объявлен');

    $editor = block_editor_markup();
    assert_contains("'quote' => 'Цитата'", $editor, 'цитата предлагается редактору');
    // «Плитка с числом» не читалась как показатель, хотя это ровно он.
    assert_contains("'stat' => 'Показатель'", $editor, 'показатель назван своим именем');
    assert_contains('data-collage-fields="quote"', $editor, 'у цитаты своя группа полей');

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains('quote:', $js, 'скрипт знает, какие поля показывать у цитаты');
});

test('Текст цитаты не спорит с надписью печати за одно поле формы', function (): void {
    // У печати поле зовётся `text`. Два поля с одним именем в форме затирают
    // друг друга — при отправке побеждает последнее, и редактор теряет то,
    // что набрал в первом.
    $editor = block_editor_markup();
    assert_contains("\$p('quote_text')", $editor, 'у цитаты свой ключ');

    // Слова без коротких предлогов: нормализатор привязывает их к соседу
    // неразрывным пробелом, и сравнение строк ловило бы типографику, а не то,
    // ради чего написан тест.
    $data = CollageBlockNormalizer::normalize(['items' => [
        ['type' => 'quote', 'quote_text' => 'Прямая речь', 'author' => 'Имя', 'role' => 'Должность'],
        ['type' => 'badge', 'text' => 'Круглая надпись'],
    ]]);

    assert_same('Прямая речь', $data['items'][0]['quote_text']);
    assert_same('Круглая надпись', $data['items'][1]['text']);
    assert_true(!isset($data['items'][0]['text']), 'цитата не занимает ключ печати');
});

test('Цитата без текста в композицию не попадает', function (): void {
    // Подпись без самой цитаты — имя в пустой ячейке: элемент занимал бы
    // место в композиции и ничем его не заполнял.
    $data = CollageBlockNormalizer::normalize(['items' => [
        ['type' => 'quote', 'author' => 'Только подпись', 'role' => 'Должность'],
    ]]);

    assert_same([], $data['items']);
});

test('Приставка показателя — отдельное поле, а не часть числа', function (): void {
    // Тот же довод, что в блоке «Показатели»: «более» перед числом это слово,
    // и набранное одной строкой оно ломает отсчёт при появлении и перенос
    // длинного значения.
    $data = CollageBlockNormalizer::normalize(['items' => [
        ['type' => 'stat', 'prefix' => 'более', 'value' => '128', 'label' => 'проектов'],
    ]]);

    assert_same('более', $data['items'][0]['prefix']);
    assert_same('128', $data['items'][0]['value']);

    assert_contains('collage__stat-prefix', (string) file_get_contents(APP_ROOT . '/templates/blocks/collage.php'), 'приставка печатается');
    assert_contains('.collage__stat-prefix', (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/collage.css'), 'у приставки есть правило');
});

test('Цитата размечена как цитата и её знак не читается диктором', function (): void {
    $tpl = (string) file_get_contents(APP_ROOT . '/templates/blocks/collage.php');

    // Прямая речь — это blockquote с cite, иначе диктор читает её как обычный
    // абзац, а поиск не отличает от остального текста ячейки.
    assert_contains('<blockquote class="collage__quote">', $tpl);
    assert_contains('<cite class="collage__quote-author">', $tpl);
    // Знак кавычки декоративен: «левая двойная кавычка» посреди фразы сбивает.
    assert_contains('collage__quote-mark" aria-hidden="true"', $tpl);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/collage.css');
    foreach (['.collage__item--quote', '.collage__quote-text', '.collage__quote-by', '.collage__quote-role'] as $selector) {
        assert_contains($selector, $css, 'у цитаты нет правила: ' . $selector);
    }
});

test('Длинная цитата в невысокой ячейке предупреждает редактора', function (): void {
    // Текст, не поместившийся в ячейку, гасится маской: обрыв виден, но
    // причина — нет. Ячейку задаёт раскладка, а длину текста — поле в другом
    // месте формы, и связать одно с другим редактору неоткуда.
    $data = CollageBlockNormalizer::normalize(['layout' => 'hero', 'items' => [
        ['type' => 'photo', 'image' => '/uploads/public/a.jpg'],
        ['type' => 'quote', 'quote_text' => str_repeat('Длинная цитата, которая никак не помещается. ', 3), 'author' => 'А. К.'],
        ['type' => 'stat', 'value' => '12', 'label' => 'районов'],
    ]]);

    assert_same(1, (int) $data['items'][1]['row_span'], 'спутник занимает одну строку сетки');
    $hints = \App\Core\BlockHints::forBlock('collage', $data);
    assert_true(
        $hints !== [] && str_contains(implode(' ', $hints), 'Цитата длиннее'),
        'редактору сказано, почему текст оборвётся'
    );

    // Короткая цитата в той же ячейке подсказки не вызывает: предупреждение
    // на каждом блоке перестают читать.
    $short = CollageBlockNormalizer::normalize(['layout' => 'hero', 'items' => [
        ['type' => 'photo', 'image' => '/uploads/public/a.jpg'],
        ['type' => 'quote', 'quote_text' => 'Коротко и по делу.', 'author' => 'А. К.'],
        ['type' => 'stat', 'value' => '12', 'label' => 'районов'],
    ]]);
    assert_same([], \App\Core\BlockHints::forBlock('collage', $short));

    // Гасит текст маска, а не обрезка по краю: резалось по середине букв, и
    // это читалось как поломка вёрстки, а не как продолжение.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/collage.css');
    assert_contains('mask-image: linear-gradient', $css, 'обрыв цитаты гасится, а не режется');
    assert_contains('.collage__quote-by { flex: 0 0 auto; }', $css, 'подпись не ужимается вместе с текстом');
});
