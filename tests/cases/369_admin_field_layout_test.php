<?php

declare(strict_types=1);

use App\Core\AdminUi;
use App\Core\BlockData\BlockFieldSchema;

/*
 * Настройки блока читаются глазами, а не перебором.
 *
 * Схема полей рисовала их одним столбцом: у «Карточек» настроек шестнадцать,
 * и «Раскладка», «Колонок», «Стиль карточек», «Размер иконок», «Фон иконок»,
 * «Положение иконки», «Выравнивание текста» шли подряд полями одинакового
 * веса. Замерено: форма блока была высотой 2430px, из них на экран
 * помещалась треть.
 *
 * Договорённость: поля занимают рабочую ширину и идут адаптивной сеткой — три
 * колонки на широком экране, две на ноутбуке и одна на телефоне. Соседние
 * настройки одного смысла собраны группой с заголовком. Подпись группы объявляется у самого поля
 * (`Field::group()`), поэтому второго списка, который разъедется с первым, не
 * появляется.
 */
test('Схема полей раскладывает настройки сеткой и группами', function (): void {
    $html = BlockFieldSchema::formHtml('cards_grid', []);

    assert_contains('<div class="bf-fields">', $html, 'поля обязаны лежать в общей сетке');
    assert_contains('<legend>Содержимое</legend>', $html, 'источник и лимит — одна группа');
    assert_contains('<legend>Вид и раскладка</legend>', $html, 'вариант, раскладка и колонки — одна группа');
    assert_contains('<legend>Карточка с иконкой</legend>', $html, 'настройки иконок — одна группа');

    // Длинная подпись варианта в половине колонки обрезается, поэтому такой
    // список занимает строку целиком.
    assert_contains('class="form-field bf-field--full"', $html, 'список с длинными подписями занимает всю строку');

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    assert_contains('.bf-fields {', $css, 'сетка полей описана в CSS');
    assert_contains('.bf-group__fields {', $css, 'у группы своя сетка');
    assert_contains('grid-template-columns: repeat(3, minmax(0, 1fr));', $css, 'на широком экране — три колонки');
    assert_contains('.bf-fields input[type="text"]', $css, 'поля схемы используют ширину своей колонки');
    assert_contains('grid-template-columns: minmax(0, 1fr);', $css, 'на узком экране — одна колонка');
});

/*
 * Условие показа поднимается на группу целиком, когда оно у всех её полей
 * одинаково: иначе при другом варианте блока осталась бы рамка с заголовком и
 * пустотой внутри. Ровно так же виджет «Меню раздела» не печатает `<aside>`
 * без содержимого — рамка с одним заголовком читается как поломка.
 */
test('Группа с общим условием показа скрывается целиком, а не полями', function (): void {
    $html = BlockFieldSchema::formHtml('cards_grid', []);

    assert_contains(
        '<fieldset class="bf-group" data-field-when="variant" data-field-value="icon"><legend>Карточка с иконкой</legend>',
        $html,
        'условие обязано стоять на самой группе'
    );

    // И не остаётся на полях внутри: два места скрытия разъехались бы, а поле
    // осталось бы скрытым навсегда, если группа однажды потеряет условие.
    $group = substr($html, (int) strpos($html, 'Карточка с иконкой'));
    $group = substr($group, 0, (int) strpos($group, '</fieldset>'));
    assert_not_contains('data-field-when', $group, 'условие не дублируется внутри группы');
});

/*
 * Поле цвета: одно управление вместо двух.
 *
 * Значение необязательно, и состояние «по умолчанию» жило подписанной
 * галочкой рядом с полем. На длинной подписи («Использовать общую настройку
 * обложки») она вылезала за колонку и читалась как отдельная настройка, а сам
 * образец в этом состоянии показывал прозрачную шашку — то есть «прозрачный»,
 * значение, которого у поля нет вовсе (alpha: false).
 */
test('Поле цвета показывает состояние и возврат к умолчанию одним управлением', function (): void {
    $html = AdminUi::colorField('overlay_color', '', 'Цвет наложения', '#0b1a30', 'Как у обложки');

    assert_contains('data-colorfield-default="Как у обложки"', $html, 'подпись состояния уходит в само поле');
    assert_contains('--colorfield-swatch: #0b1a30', $html, 'образец показывает цвет умолчания, а не пустоту');
    assert_contains('data-colorfield-reset', $html, 'возврат к умолчанию — кнопка внутри поля');
    assert_contains('name="overlay_color_off"', $html, 'договор с сервером не меняется: галочка читается раньше значения');

    // Кнопка приходит скрытой: без JavaScript нажимать было бы не на что,
    // и состояние там по-прежнему переключает сама галочка.
    assert_true(
        (bool) preg_match('/<button[^>]*data-colorfield-reset[^>]*\shidden/', $html),
        'кнопка возврата обязана приходить скрытой'
    );

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains("group.classList.add('is-enhanced')", $js, 'со скриптом поле переходит на одно управление');
    assert_contains('adminThemeMode()', $js, 'пикер обязан спрашивать текущий внешний вид панели');
    assert_contains("getAttribute('data-admin-appearance')", $js, 'вид панели — это data-admin-appearance, а не имя старой темы');
    assert_not_contains(
        "getAttribute('data-admin-theme') === 'dark_emerald'",
        $js,
        'имени цветовой темы больше нет — тёмный вид приходит настройкой внешнего вида'
    );

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    assert_contains('.colorfield.is-enhanced .colorfield__off { display: none; }', $css, 'галочка остаётся вариантом без JS');
});

/*
 * Палитра пикера — цвета самого сайта. Прежде там лежал зашитый список синих
 * и бирюзовых тонов, к сайту отношения не имевший.
 */
test('Образцы пикера берутся из палитры сайта', function (): void {
    $swatches = AdminUi::colorSwatches();

    assert_true($swatches !== [], 'палитра не бывает пустой');
    assert_same(array_values(array_unique($swatches)), $swatches, 'повторов в палитре быть не должно');
    foreach ($swatches as $hex) {
        assert_true((bool) preg_match('/^#[0-9a-f]{3,8}$/', $hex), 'образец обязан быть цветом: ' . $hex);
    }

    $header = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    assert_contains('data-color-swatches', $header, 'палитра доезжает до скрипта разметкой');

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains("getAttribute('data-color-swatches')", $js, 'скрипт читает палитру из разметки');
});

/*
 * Тёмный вид панели: «мягкая» поверхность обязана быть тёмной.
 *
 * Токен `--admin-surface-soft` (подложка групп, шапка таблицы, `.bulk-bar`,
 * рельс медиабиблиотеки, превью медиаполя) объявлялся только в светлой шкале,
 * и в тёмной панели оставался белым `#fafbfc` под светлым текстом — замерено
 * 1.02:1 при норме 4.5:1. Эталон вычисленных стилей это состояние честно
 * записывал: он сверяет значения, а не судит их.
 *
 * Тёмные правила объявлены дважды (явный выбор и «как в системе»), и списки
 * свойств обязаны совпадать построчно — поэтому проверяем оба блока.
 */
test('Тёмный вид панели объявляет мягкую поверхность в обоих блоках', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');

    $blocks = [
        ':root[data-admin-appearance="dark"] {',
        ':root[data-admin-appearance="system"] {',
    ];
    foreach ($blocks as $start) {
        $at = strpos($css, $start);
        assert_true($at !== false, 'не найден блок тёмных токенов: ' . $start);
        $body = substr($css, (int) $at, (int) strpos($css, '}', (int) $at) - (int) $at);
        assert_contains('--admin-surface-soft:', $body, 'мягкая поверхность осталась светлой в блоке ' . $start);
        assert_not_contains('--admin-surface-soft: #fafbfc', $body, 'светлое значение в тёмной шкале');
    }
});
