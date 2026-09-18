<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Раскладка формы обратной связи: набор типов полей, шкала ширин и сетка.
 *
 * Прежде эти знания лежали в четырёх местах — список типов повторялся в форме
 * редактора, в её шаблоне репитера, в белом списке контроллера и ветками в
 * публичном шаблоне, — и расходились молча: тип, добавленный в форму, но не в
 * контроллер, сохранялся как «Текст». Ширина поля вообще не была настройкой:
 * её вычислял публичный шаблон по типу, поэтому «Имя» и «Фамилия» нельзя было
 * поставить в один ряд, а длинный список занимал половину ряда всегда.
 */
final class FormLayout
{
    /**
     * Дорожек в сетке формы. Шесть делятся и на две колонки, и на три,
     * поэтому одна шкала ширин годится любой раскладке: половина — три
     * дорожки, треть — две. Ряд из четырёх колонок на форме не нужен: поле
     * ввода уже 320px, а подпись над ним длиннее самого поля.
     */
    public const GRID = 6;

    /**
     * Ширина поля — доля ряда, а не пиксели: форма стоит и во всю ширину
     * страницы, и в колонке конструктора.
     *
     * `auto` означает «как задано у блока»: у формы в одну колонку такое поле
     * занимает ряд целиком, в две — половину. Это и есть прежнее поведение,
     * поэтому у полей, сохранённых до появления настройки, вид не меняется.
     *
     * @var array<string, array{label: string, span: int}>
     */
    public const WIDTHS = [
        'auto' => ['label' => 'Как в сетке формы', 'span' => 0],
        'full' => ['label' => 'Во всю ширину', 'span' => self::GRID],
        'two_thirds' => ['label' => 'Две трети ряда', 'span' => 4],
        'half' => ['label' => 'Половина ряда', 'span' => 3],
        'third' => ['label' => 'Треть ряда', 'span' => 2],
    ];

    /** @var array<string, string> Тип поля → подпись в панели. */
    public const TYPES = [
        'text' => 'Текст',
        'email' => 'Email',
        'tel' => 'Телефон',
        'textarea' => 'Многострочный текст',
        'file' => 'Файл',
        'select' => 'Выпадающий список',
        'radio' => 'Радио-кнопки',
        'checkbox_group' => 'Группа чекбоксов',
        'checkbox' => 'Одиночный чекбокс',
        'date' => 'Дата',
    ];

    /** Типы, у которых спрашиваются варианты выбора. */
    public const OPTION_TYPES = ['select', 'radio', 'checkbox_group'];

    /**
     * Типы, которым узкая колонка не годится: у многострочного текста и списка
     * вариантов половина ряда режет содержимое, а не экономит место. Это
     * умолчание, а не запрет — редактор задаёт ширину сам.
     */
    private const FULL_BY_TYPE = ['textarea', 'file', 'checkbox_group', 'checkbox'];

    /** @var array<int, string> Колонок в сетке формы (настройка блока). */
    public const COLUMNS = [
        1 => 'В одну колонку',
        2 => 'В две колонки',
        3 => 'В три колонки',
    ];

    public static function normalizeType(string $type): string
    {
        return isset(self::TYPES[$type]) ? $type : 'text';
    }

    public static function normalizeWidth(string $width): string
    {
        return isset(self::WIDTHS[$width]) ? $width : 'auto';
    }

    /**
     * Ширина поля: сохранённая либо выведенная из типа. Значение вне набора
     * заменяется умолчанием, а не ближайшим допустимым — в списке его нет,
     * значит форма подделана.
     *
     * @param array<string, mixed> $field
     */
    public static function widthOf(array $field): string
    {
        $stored = (string) ($field['width'] ?? '');
        if ($stored !== '' && isset(self::WIDTHS[$stored])) {
            return $stored;
        }

        $type = self::normalizeType((string) ($field['type'] ?? 'text'));

        return in_array($type, self::FULL_BY_TYPE, true) ? 'full' : 'auto';
    }

    /** Класс поля публичной формы и карточки в конструкторе: одна шкала на оба. */
    public static function widthClass(string $width, string $prefix): string
    {
        return $prefix . '--w-' . str_replace('_', '-', self::normalizeWidth($width));
    }

    /** @param array<string, mixed> $field */
    public static function fieldClass(array $field): string
    {
        return 'block-form__field ' . self::widthClass(self::widthOf($field), 'block-form__field');
    }

    /**
     * Колонок у формы. Читается и прежний ключ `layout` (1col/2col): данные
     * блока приезжают ещё и из файла шаблона страницы, а переименование
     * настройки не должно менять вид собранных страниц.
     *
     * @param array<string, mixed> $data
     */
    public static function columns(array $data): int
    {
        $columns = (int) ($data['columns'] ?? 0);
        if (isset(self::COLUMNS[$columns])) {
            return $columns;
        }

        return ((string) ($data['layout'] ?? '') === '2col') ? 2 : 1;
    }

    /** @param array<string, mixed> $data */
    public static function formClass(array $data): string
    {
        return 'block-form__form block-form__form--cols-' . self::columns($data);
    }
}
