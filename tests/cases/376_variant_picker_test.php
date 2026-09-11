<?php

declare(strict_types=1);

use App\Core\AdminUi;
use App\Core\BlockData\BlockFieldSchema;
use App\Core\VariantPreview;

/*
 * Вариант отображения показывается, а не называется.
 *
 * Он был выпадающим списком, и чтобы узнать, чем «Мозаика» отличается от
 * «Колонок», редактор выбирал значение, сохранял блок и открывал страницу —
 * то есть проверял выбор публикацией. Плитка отвечает на тот же вопрос до
 * сохранения: рисунок раскладки плюс строка о том, чем этот вариант
 * отличается от соседнего.
 *
 * Договорённость: у каждого значения поля-варианта есть и рисунок, и
 * пояснение, объявленные одним списком у самого поля (`Field::variants()`).
 * Двух списков рядом («значение → картинка» и «значение → текст») быть не
 * должно: они разъедутся при первом же новом варианте, и плитка останется
 * либо без рисунка, либо без подписи.
 */

/** Поля, которые выбирают вид блока: ключ данных => тип блока. */
function variant_fields(): array
{
    $out = [];
    foreach (BlockFieldSchema::all() as $type => $fields) {
        foreach ($fields as $key => $field) {
            if (in_array($key, ['variant', 'layout', 'card_style'], true) && $field->kind === 'enum') {
                $out[] = [$type, $key, $field];
            }
        }
    }

    return $out;
}

test('У каждого варианта отображения есть рисунок раскладки и пояснение', function (): void {
    $fields = variant_fields();
    assert_true(count($fields) > 15, 'полей выбора вида должно быть больше пятнадцати');

    foreach ($fields as [$type, $key, $field]) {
        $where = "{$type}.{$key}";
        assert_true($field->variants !== [], "{$where}: вариант рисуется списком, а не плитками");

        foreach (array_keys($field->options) as $value) {
            $value = (string) $value;
            assert_true(isset($field->variants[$value]), "{$where}: у значения «{$value}» нет описания");
            [$shape, $note] = $field->variants[$value];

            assert_true(
                VariantPreview::isKnown($shape),
                "{$where}: раскладка «{$shape}» неизвестна VariantPreview — плитка выйдет пустой"
            );
            assert_true($note !== '', "{$where}: у значения «{$value}» нет пояснения");
            // Пояснение отвечает на вопрос «чем это отличается от соседнего».
            // Пересказ названия не отвечает ни на что и занимает место.
            assert_true(
                mb_strtolower($note) !== mb_strtolower((string) $field->options[$value]),
                "{$where}: пояснение «{$note}» повторяет название варианта"
            );
            assert_true(
                mb_strlen($note) <= 90,
                "{$where}: пояснение длиннее 90 знаков — в плитке оно не поместится"
            );
        }
    }
});

test('Плитка варианта — одно управление: радиокнопка, рисунок и подпись', function (): void {
    $html = AdminUi::variantField(
        'variant',
        'band',
        ['card' => 'Карточка', 'band' => 'Полоса'],
        [
            'card' => ['card', 'Заголовок, текст и кнопка в карточке'],
            'band' => ['band', 'Узкая полоса во всю ширину'],
        ],
        'Вариант блока',
        'Подсказка поля'
    );

    // Без JavaScript выбор обязан работать: это обычные радиокнопки, а не
    // плитки со скрытым `<select>` рядом — второе управление с тем же именем
    // мы уже проходили на поле цвета.
    assert_same(2, substr_count($html, '<input class="variant-card__input" type="radio"'));
    assert_not_contains('<select', $html);
    assert_contains('value="band" checked', $html, 'выбранное значение отмечено');
    assert_contains('Узкая полоса во всю ширину', $html, 'пояснение выводится');
    assert_contains('<svg', $html, 'рисунок раскладки выводится');
    // Диктору набор прямоугольников не говорит ничего, поэтому рисунок скрыт
    // от него, а раскладка названа словами.
    assert_contains('aria-hidden="true"', $html);
    assert_contains('Раскладка: сплошная полоса', $html);
    assert_contains('Подсказка поля', $html);
});

test('Неизвестное значение не оставляет плитки без выбора', function (): void {
    // Данные приезжают и из старых записей, и из загруженного файла шаблона
    // страницы: значение оттуда может не существовать вовсе. Пустой выбор
    // читался бы как «ничего не выбрано», хотя на сайте блок чем-то да
    // рисуется — поэтому отмечается первый вариант, он же умолчание схемы.
    $html = AdminUi::variantField(
        'variant',
        'выдуманное',
        ['card' => 'Карточка', 'band' => 'Полоса'],
        ['card' => ['card', 'Первый'], 'band' => ['band', 'Второй']],
        'Вариант блока'
    );

    assert_contains('value="card" checked', $html);
    assert_same(1, substr_count($html, 'checked'));
});

test('Схема рисует вариант плитками, а остальные списки оставляет списками', function (): void {
    $html = BlockFieldSchema::formHtml('cards_grid', []);

    assert_contains('class="variant-field"', $html, 'вариант — плитки');
    // «Колонок» и «Размер иконок» плитками не рисуются: у них не раскладка, а
    // число, и рисовать шесть почти одинаковых схем значило бы спрятать выбор
    // варианта среди них.
    assert_contains('<select id="bf_columns"', $html);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    assert_contains('.variant-card__input:checked + .variant-card', $css, 'выбранная плитка отмечена в CSS');
    assert_contains('.variant-card__input:focus-visible + .variant-card', $css, 'фокус виден с клавиатуры');
    // Рамка есть у всех плиток и в покое прозрачная: иначе выбор двигал бы
    // соседей на её толщину.
    assert_contains('border: 1px solid transparent;', $css);
});

test('Блок «Текст» выбирает вариант тем же виджетом, что и блоки на схеме', function (): void {
    // Четыре типа остались вне схемы полей, и «Текст» — один из них. Свой
    // выпадающий список там означал бы, что один и тот же выбор показан двумя
    // разными способами.
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');

    assert_contains('AdminUi::variantField(', $form, 'вариант «Текста» рисуется общим виджетом');
    assert_not_contains('<select id="text_variant"', $form, 'старого выпадающего списка быть не должно');
});

test('Рисунок раскладки собирается из описания, а не рисуется по одному', function (): void {
    // Три с лишним десятка почти одинаковых картинок разъехались бы между
    // собой при первой правке, поэтому миниатюра описывается строкой вида
    // `grid:3+icon`, а рисует её один генератор.
    assert_true(VariantPreview::isKnown('grid:3+icon'));
    assert_true(VariantPreview::isKnown('track:3+photo'));
    assert_false(VariantPreview::isKnown('выдуманная-схема'), 'неизвестная схема обязана отвергаться');
    assert_false(VariantPreview::isKnown('grid:3+выдумка'), 'неизвестный модификатор обязан отвергаться');

    // Полоса отличается от сетки ровно тем, что последняя карточка обрезана
    // краем: если все помещаются, миниатюра врёт.
    $track = VariantPreview::svg('track:3');
    preg_match_all('/x="([\d.]+)" y="4" width="([\d.]+)"/', $track, $m);
    $rightmost = 0.0;
    foreach ($m[1] as $i => $x) {
        $rightmost = max($rightmost, (float) $x + (float) $m[2][$i]);
    }
    assert_true($rightmost >= 63.0, 'последняя карточка полосы обязана доходить до края холста');

    // Цвет наследуется, а не задаётся: в тёмной панели и у выбранной плитки
    // рисунок обязан меняться вместе с текстом.
    assert_contains('currentColor', $track);
    assert_not_contains('#', $track, 'цвета в миниатюре быть не должно');
});
