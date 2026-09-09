<?php

declare(strict_types=1);

use App\Core\BlockTypeRegistry;

// Настройки блоков: у каждой должен быть потребитель на выводе, поле в форме и
// ветка сохранения. Иначе настройка «есть», редактор её переключает, а на сайте
// ничего не меняется — так уже случалось с фирменным знаком карточек актов.

test('Каждое поле блока читается на выводе', function () {
    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/BlockRenderer.php');
    $orphans = [];

    foreach (BlockTypeRegistry::defaults() as $type => $defaults) {
        $tplPath = APP_ROOT . '/templates/blocks/' . $type . '.php';
        $tpl = is_file($tplPath) ? (string) file_get_contents($tplPath) : '';
        // Партиалы карточек лежат рядом: шаблон их подключает, поля читают они.
        foreach (glob(APP_ROOT . '/templates/blocks/partials/*.php') ?: [] as $partial) {
            $tpl .= (string) file_get_contents($partial);
        }
        foreach (array_keys($defaults) as $key) {
            $used = str_contains($tpl, "'" . $key . "'")
                || str_contains($tpl, '"' . $key . '"')
                || str_contains($renderer, "'" . $key . "'");
            if (!$used) {
                $orphans[] = $type . '.' . $key;
            }
        }
    }

    assert_same([], $orphans, 'настройки без потребителя: ' . implode(', ', $orphans));
});

test('Фирменный знак карточек актов включается настройкой блока', function () {
    $defaults = BlockTypeRegistry::defaultsFor('docs_list');
    assert_true(array_key_exists('emblem', $defaults), 'настройка объявлена в DEFAULTS');
    assert_true((bool) $defaults['emblem'], 'по умолчанию знак показывается');

    assert_contains('name="emblem"', block_editor_markup(), 'у настройки есть поле в редакторе блока');

    // Тип переехал на схему: значение приходит из неё и на сохранении, и на
    // выводе — отдельной ветки в контроллере у него больше нет.
    $emblem = \App\Core\BlockData\BlockFieldSchema::fields('docs_list')['emblem'] ?? null;
    assert_true($emblem !== null && $emblem->kind === 'bool', 'настройка описана схемой');
    assert_same(true, \App\Core\BlockData\BlockFieldSchema::normalize('docs_list', ['emblem' => '1'], 'ru')['emblem']);
    assert_same(false, \App\Core\BlockData\BlockFieldSchema::normalize('docs_list', [], 'ru')['emblem']);

    $card = (string) file_get_contents(APP_ROOT . '/templates/blocks/partials/act_card.php');
    assert_contains('$showEmblem', $card, 'карточка спрашивает настройку');
    // Блоки, сохранённые до появления настройки, ключа не имеют — знак остаётся.
    assert_contains("!isset(\$showEmblem)", $card, 'у старых блоков знак не пропадает');
});

test('Поля, зависящие от варианта, помечены для формы', function () {
    $editor = block_editor_markup();
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    assert_contains('data-field-when="variant"', $editor, 'неприменимые поля помечены');
    assert_contains('data-field-when', $js, 'разметку обрабатывает скрипт админки');
    // Без JS поле остаётся видимым: скрытие — подсказка, а не условие сохранения.
    assert_not_contains('data-field-when-required', $editor);
});
