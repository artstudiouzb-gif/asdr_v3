<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockTypeRegistry;

/*
 * Схема настроек блока: одно описание вместо четырёх копий.
 *
 * Прежде настройка жила в четырёх местах — умолчание в реестре, поле в
 * block_form.php, ветка в collectData() и повторная проверка в шаблоне. Списки
 * допустимых значений расходились молча: форма отдавала значение, нормализатор
 * его принимал, а шаблон о нём не знал и откатывался к умолчанию.
 *
 * Проверяем ровно это: у типа со схемой всё перечисленное берётся из неё, а
 * шаблон второй копии списка не содержит.
 */

test('Умолчания типа со схемой берутся из схемы, а не из реестра', function () {
    foreach (BlockTypeRegistry::BASE_DEFAULTS as $type => $base) {
        if (BlockFieldSchema::has($type)) {
            assert_same([], $base, "у типа со схемой в BASE_DEFAULTS не должно быть своих полей: {$type}");
            assert_same(
                BlockFieldSchema::defaults($type),
                BlockTypeRegistry::defaultsFor($type),
                "умолчания {$type} обязаны приходить из схемы"
            );
        } else {
            assert_true($base !== [], "тип без схемы обязан объявить умолчания: {$type}");
        }
    }

    // Порядок типов — это порядок в редакторе, переезд на схему его не меняет.
    assert_same(array_keys(BlockTypeRegistry::BASE_DEFAULTS), array_keys(BlockTypeRegistry::defaults()));
});

test('Поля схемы описаны полностью и осмысленно', function () {
    foreach (BlockFieldSchema::all() as $type => $fields) {
        assert_true($fields !== [], "пустая схема бессмысленна: {$type}");
        foreach ($fields as $key => $field) {
            assert_true(in_array($field->kind, \App\Core\BlockData\Field::KINDS, true), "неизвестный тип поля {$type}.{$key}");
            assert_true($field->label !== '', "поле без подписи: {$type}.{$key}");
            if ($field->kind === 'enum') {
                assert_true(count($field->options) > 1, "список из одного значения не настройка: {$type}.{$key}");
                assert_true(
                    isset($field->options[(string) $field->default]),
                    "умолчание вне списка значений: {$type}.{$key}"
                );
            }
            if ($field->kind === 'int' && $field->max !== null) {
                assert_true($field->min < $field->max, "пустой диапазон: {$type}.{$key}");
            }
            if ($field->kind === 'int_choice') {
                assert_true(count($field->options) > 1, "список из одного числа не настройка: {$type}.{$key}");
                assert_true(
                    isset($field->options[(int) $field->default]),
                    "умолчание вне списка значений: {$type}.{$key}"
                );
            }
        }
    }
});

test('Присланное формой значение приводится к схеме', function () {
    $data = BlockFieldSchema::normalize('partners', [
        'variant' => 'marquee',
        'columns' => '99',              // не из списка — умолчание
        'logo_size' => 'gigantic',      // чужое значение — умолчание
        'autoplay' => '7',
        'title_field' => '  Партнёры  ',
        'all_url' => 'javascript:alert(1)',
    ], 'ru');

    assert_same('marquee', $data['variant']);
    assert_same(6, $data['columns'], 'число вне списка — это подделанная форма, берём умолчание');
    assert_same('medium', $data['logo_size']);
    assert_same(7, $data['autoplay']);
    assert_same('Партнёры', $data['title']);
    assert_same('', $data['all_url'], 'опасная ссылка не сохраняется');
    // Флажок без отметки приходит отсутствием поля, а не нулём.
    assert_same(false, $data['grayscale']);
});

test('Сохранённые данные тоже приводятся к схеме — на выводе', function () {
    // Так выглядит блок из старой записи или из загруженного файла шаблона
    // страницы: ключи проверены, значения — нет.
    $data = BlockFieldSchema::apply('columns', [
        'columns' => 9,
        'gap' => 'huge',
        'valign' => 'diagonal',
        'mobile_order' => 'reverse',
    ]);

    assert_same(2, $data['columns'], 'значение вне списка заменяется умолчанием');
    assert_same('medium', $data['gap']);
    assert_same('stretch', $data['valign']);
    assert_same('reverse', $data['mobile_order']);
});

test('Форма и сохранение типа со схемой обращаются к ней, а не к рукописным полям', function () {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    // Сохранение живёт либо веткой контроллера, либо отдельным нормализатором
    // типа — важно, что скалярные поля в обоих случаях идут через схему.
    $saving = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    foreach (glob(APP_ROOT . '/app/Core/BlockData/*Normalizer.php') ?: [] as $normalizer) {
        $saving .= (string) file_get_contents($normalizer);
    }

    foreach (array_keys(BlockFieldSchema::all()) as $type) {
        assert_contains("BlockFieldSchema::formHtml('{$type}'", $form, "форма {$type} рисуется по схеме");
        assert_contains("BlockFieldSchema::normalize('{$type}'", $saving, "сохранение {$type} идёт по схеме");

        $html = BlockFieldSchema::formHtml($type, []);
        foreach (BlockFieldSchema::fields($type) as $key => $field) {
            assert_contains('name="' . $field->inputName($key) . '"', $html, "поле {$type}.{$key} есть в форме");
        }
    }
});

test('Новые настройки колонок доезжают до разметки', function () {
    // id = 0: без реального id блок не ходит в БД за вложенными блоками, и
    // обёртку контейнера можно проверить без базы.
    $render = static function (array $data): string {
        $rendered = \App\Core\BlockRenderer::render([
            'id' => 0,
            'type' => 'columns',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'custom_css' => '',
        ]);

        return (string) $rendered['html'];
    };

    $default = $render([]);
    assert_not_contains('cms-columns--valign', $default, 'растяжение — поведение сетки по умолчанию, класса ему не нужно');
    assert_not_contains('cms-columns--mobile-reverse', $default);

    $changed = $render(['valign' => 'center', 'mobile_order' => 'reverse']);
    assert_contains('cms-columns--valign-center', $changed);
    assert_contains('cms-columns--mobile-reverse', $changed);
});

test('Шаблон типа со схемой не перепроверяет значения', function () {
    // Второй список допустимых значений — это и есть та копия, ради которой всё
    // затевалось: она разъезжается с первой молча.
    $partners = (string) file_get_contents(APP_ROOT . '/templates/blocks/partners.php');
    assert_not_contains('in_array(', $partners, 'значения уже проверены схемой');

    // Общее правило: значение поля со списком или границами шаблон читает
    // прямо. Запасное `?? умолчание` рядом с ним — верный признак того, что
    // проверка написана заново.
    $fallbacks = [];
    foreach (BlockFieldSchema::all() as $type => $fields) {
        $path = APP_ROOT . '/templates/blocks/' . $type . '.php';
        if (!is_file($path)) {
            continue; // контейнеры рендерятся программно
        }
        $template = (string) file_get_contents($path);
        foreach ($fields as $key => $field) {
            if (!in_array($field->kind, ['enum', 'int', 'int_choice', 'bool'], true)) {
                continue;
            }
            if (str_contains($template, "\$data['" . $key . "'] ?? ")) {
                $fallbacks[] = $type . '.' . $key;
            }
        }
    }
    assert_same([], $fallbacks, 'шаблон повторяет проверку схемы: ' . implode(', ', $fallbacks));

    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/BlockRenderer.php');
    assert_contains('BlockFieldSchema::apply($type, $data)', $renderer, 'рендер приводит данные к схеме');
});

test('У каждого типа блока в редакторе есть свои поля', function () {
    // Сторож против правки формы вслепую. `block_form.php` — две тысячи строк
    // из веток `if ($type === ...)`, и при переносе полей на схему легко
    // срезать лишнее вместе с закрывающим `endif`: тогда ветка соседнего типа
    // проглатывает следующую — один тип показывает чужие поля, а другой не
    // показывает ничего и теряет содержимое при первом сохранении. Так уже
    $form = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php'));

    $covered = [];
    preg_match_all(
        "/^        <\\?php if \\(\\\$type === '([a-z_]+)'\\): \\?>$/m",
        $form,
        $single,
        PREG_OFFSET_CAPTURE | PREG_SET_ORDER
    );
    foreach ($single as $match) {
        $type = $match[1][0];
        $covered[] = $type;
        $start = (int) $match[0][1];
        $endPos = strpos($form, "\n        <?php endif; ?>", $start);
        $section = $endPos === false ? '' : substr($form, $start, $endPos - $start);
        assert_true(
            str_contains($section, "formHtml('" . $type . "'") || str_contains($section, 'name="'),
            "ветка {$type} осталась без полей — проверьте, не срезан ли закрывающий endif"
        );
    }
    // Ветки на несколько типов сразу: `in_array($type, [...])`.
    preg_match_all("/in_array\\(\\\$type, \\[([^\\]]+)\\], true\\)/", $form, $groups);
    foreach ($groups[1] as $list) {
        preg_match_all("/'([a-z_]+)'/", $list, $names);
        $covered = array_merge($covered, $names[1]);
    }

    $missing = [];
    foreach (array_keys(BlockTypeRegistry::defaults()) as $type) {
        if (!in_array($type, $covered, true)) {
            $missing[] = $type;
        }
    }
    assert_same([], $missing, 'типы без полей в редакторе: ' . implode(', ', $missing));
});

test('Репитеры блоков различают свои наборы строк', function () {
    // «Иконка и текст» и «Карточка руководителя» держат по репитеру `items`, но
    // строки у них разные: у первого — цвет иконки и текст строк, у второго —
    // подпись и значение. Когда ветки слиплись, репитер остался один, и блок
    // молча терял содержимое. Поля-приметы обоих обязаны быть в редакторе.
    $editor = block_editor_markup();

    assert_contains('items[__INDEX__][icon_color]', $editor, '«Иконка и текст»: цвет иконки строки');
    assert_contains('items[__INDEX__][rows]', $editor, '«Иконка и текст»: строки карточки');
    assert_contains('items[__INDEX__][label]', $editor, '«Карточка руководителя»: подпись строки');
    assert_contains('items[__INDEX__][value]', $editor, '«Карточка руководителя»: значение строки');
});

test('Форма, разбитая на несколько вызовов схемы, не теряет полей', function () {
    // У типов, где список строк стоит посреди настроек, форма зовёт схему
    // несколько раз с перечислением ключей. Забытый в этих списках ключ —
    // это поле, пропавшее из редактора: сохранение вернёт ему умолчание, а
    // редактор даже не увидит, что настройка была.
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');

    $listed = [];
    $whole = [];
    preg_match_all(
        "/BlockFieldSchema::formHtml\\('([a-z_]+)', \\\$data(?:, \\[([^\\]]*)\\])?\\)/",
        $form,
        $calls,
        PREG_SET_ORDER
    );
    foreach ($calls as $call) {
        $type = $call[1];
        if (!isset($call[2]) || trim($call[2]) === '') {
            $whole[$type] = true;
            continue;
        }
        preg_match_all("/'([a-z_0-9]+)'/", $call[2], $keys);
        $listed[$type] = array_merge($listed[$type] ?? [], $keys[1]);
    }

    foreach ($listed as $type => $keys) {
        if (isset($whole[$type])) {
            continue; // тип рисуется и целиком — перечисление лишь дополняет
        }
        $expected = array_keys(BlockFieldSchema::fields($type));
        sort($expected);
        $keys = array_values(array_unique($keys));
        sort($keys);
        assert_same($expected, $keys, "форма {$type} потеряла поля схемы");
    }
});
