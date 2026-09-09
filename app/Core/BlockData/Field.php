<?php

declare(strict_types=1);

namespace App\Core\BlockData;

/**
 * Описание одной настройки блока: тип значения, границы, умолчание и подписи
 * для формы.
 *
 * Заводится ради того, чтобы настройка была объявлена **один раз**. Прежде она
 * жила в четырёх местах: умолчание в реестре типов, поле в `block_form.php`,
 * ветка в `BlockController::collectData()` и повторная проверка в шаблоне
 * (`in_array(...)` встречался в шаблонах блоков около сорока раз). Список
 * допустимых значений расходился молча: форма отдавала значение, нормализатор
 * его принимал, а шаблон о нём не знал и откатывался к умолчанию.
 *
 * Из одного описания получаются все четыре вещи: умолчание
 * (`BlockFieldSchema::defaults()`), поле формы (`BlockFieldSchema::formHtml()`),
 * нормализация присланного (`BlockFieldSchema::normalize()`) и гарантия для
 * шаблона: в `$data` уже лежит проверенное значение, перепроверять нечего.
 */
final class Field
{
    public const KINDS = ['enum', 'int', 'int_choice', 'bool', 'text', 'textarea', 'richtext', 'url', 'media', 'icon', 'color', 'media_position'];

    /**
     * @param array<array-key, string> $options варианты `enum`: значение => подпись.
     *     Ключ — не обязательно строка: у `intChoice` значения числовые, а PHP
     *     числовые ключи массива хранит как int, что бы ни объявили.
     * @param string $input имя поля в форме, если отличается от ключа данных
     * @param array{field:string, values:list<string>}|null $when
     *        условие применимости: поле показывается только при этом варианте
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly mixed $default,
        public readonly array $options = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly string $hint = '',
        public readonly string $input = '',
        public readonly string $placeholder = '',
        public readonly ?array $when = null,
        public readonly string $swatch = '',
    ) {
    }

    /** @param array<string, string> $options */
    public static function enum(string $label, array $options, string $default, string $hint = ''): self
    {
        return new self('enum', $label, $default, options: $options, hint: $hint);
    }

    /**
     * Целое числовым полем. Верхняя граница необязательна: у «сколько записей
     * показывать» её нет и придумывать не нужно.
     */
    public static function int(string $label, int $min, ?int $max, int $default, string $hint = ''): self
    {
        return new self('int', $label, $default, min: $min, max: $max, hint: $hint);
    }

    /**
     * Целое из готового списка — выпадающим списком. Значения перечисляются, а
     * не задаются диапазоном: набор колонок бывает с пропуском (0 — «сколько
     * поместится», затем 2–5, но не 1), и диапазон такое не выражает.
     *
     * @param list<int> $values
     * @param array<int, string> $labels подписи, отличные от самого числа
     */
    public static function intChoice(string $label, array $values, int $default, string $hint = '', array $labels = []): self
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = $labels[$value] ?? (string) $value;
        }

        return new self('int_choice', $label, $default, options: $options, hint: $hint);
    }

    public static function bool(string $label, bool $default, string $hint = ''): self
    {
        return new self('bool', $label, $default, hint: $hint);
    }

    public static function text(string $label, string $default = '', string $hint = '', string $placeholder = ''): self
    {
        return new self('text', $label, $default, hint: $hint, placeholder: $placeholder);
    }

    public static function textarea(string $label, string $hint = '', string $placeholder = ''): self
    {
        return new self('textarea', $label, '', hint: $hint, placeholder: $placeholder);
    }

    /** Форматируемый текст: в форме — редактор, на входе — санитайзер. */
    public static function richtext(string $label, string $hint = ''): self
    {
        return new self('richtext', $label, '', hint: $hint);
    }

    public static function url(string $label, string $hint = '', string $placeholder = ''): self
    {
        return new self('url', $label, '', hint: $hint, placeholder: $placeholder);
    }

    /** Картинка: адрес из медиабиблиотеки, поле с превью и очисткой. */
    public static function media(string $label, string $hint = ''): self
    {
        return new self('media', $label, '', hint: $hint);
    }

    /** Иконка Tabler: имя значка, поле с пикером. */
    public static function icon(string $label = 'Иконка', string $hint = ''): self
    {
        return new self('icon', $label, '', hint: $hint);
    }

    /**
     * Кадрирование фонового изображения: пресеты для широкого экрана и для
     * телефона.
     *
     * Единственное поле, которое владеет **двумя** ключами данных: своим и
     * тем же именем с суффиксом `_mobile`. Виджет админки рисует оба списка
     * сразу, и разносить их по двум описаниям значило бы разложить один
     * элемент управления на две половины.
     */
    public static function mediaPosition(string $label = 'Кадрирование изображения'): self
    {
        return new self('media_position', $label, 'center-center');
    }

    /** Ключ-спутник поля `media_position`; у остальных видов его нет. */
    public function companionKey(string $key): ?string
    {
        return $this->kind === 'media_position' ? $key . '_mobile' : null;
    }

    /**
     * Необязательный цвет. Пустое значение — «как в теме», поэтому у поля есть
     * отдельный флажок «по умолчанию»; `$swatch` — цвет, который показывает
     * пипетка, пока своего значения нет.
     */
    public static function color(string $label, string $swatch, string $hint = ''): self
    {
        return new self('color', $label, '', hint: $hint, swatch: $swatch);
    }

    /**
     * Подсказка о разметке заголовка. Приёмов два, и оба живут внутри самой
     * строки, поэтому объяснить их надо там же, где её набирают — у каждого
     * заголовка секции, а не в одном месте документации.
     */
    public const TITLE_MARKUP_HINT = 'Разметка внутри строки: *слово* — выделение,'
        . ' | — принудительный перенос строки. Теги в заголовок не принимаются.';

    /**
     * Имя поля в форме отличается от ключа данных. Так исторически сделан
     * заголовок секции: `name="title_field"`, потому что `title` — колонка
     * самого блока, и одноимённое поле формы затирало бы её.
     *
     * Заодно заголовок получает подсказку о разметке строки: своего текста у
     * этих полей почти нигде нет, а объяснять приём в тридцати описаниях по
     * отдельности значило бы разъехаться с ними при первой правке.
     */
    public function named(string $inputName): self
    {
        $field = $this->with(input: $inputName);

        return $inputName === 'title_field' && $field->hint === ''
            ? $field->with(hint: self::TITLE_MARKUP_HINT)
            : $field;
    }

    /**
     * Поле применимо только к части вариантов блока. Разметка та же, что у
     * рукописных полей: скрытие — подсказка редактору, а не условие
     * сохранения (без JS поле остаётся видимым).
     *
     * @param list<string> $values
     */
    public function onlyWhen(string $field, array $values): self
    {
        return $this->with(when: ['field' => $field, 'values' => $values]);
    }

    /** Имя поля формы: либо переопределённое, либо сам ключ данных. */
    public function inputName(string $key): string
    {
        return $this->input !== '' ? $this->input : $key;
    }

    /** @param array{field:string, values:list<string>}|null $when */
    private function with(string $input = '', ?array $when = null, ?string $hint = null): self
    {
        return new self(
            $this->kind,
            $this->label,
            $this->default,
            $this->options,
            $this->min,
            $this->max,
            $hint ?? $this->hint,
            $input !== '' ? $input : $this->input,
            $this->placeholder,
            $when ?? $this->when,
            $this->swatch,
        );
    }
}
