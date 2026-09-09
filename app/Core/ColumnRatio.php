<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ширина колонок у блока «Колонки».
 *
 * Пропорция хранится строкой вида «2:1» — по доле на колонку. Пустое значение
 * означает равные колонки, и тогда в разметку не уходит ничего лишнего.
 *
 * Доли считаются в `fr`, но обёрнутыми в `minmax(0, …)`: без этого широкое
 * содержимое (таблица, длинное слово, картинка) раздувает колонку и ломает
 * заданное соотношение.
 */
final class ColumnRatio
{
    /**
     * Допустимые пропорции по числу колонок. Список закрытый: произвольные
     * доли из формы принимать незачем — редактор выбирает из готовых вариантов,
     * а закрытый список заодно исключает мусор в scoped CSS.
     *
     * @var array<int, list<string>>
     */
    public const OPTIONS = [
        2 => ['2:1', '1:2', '3:1', '1:3', '3:2', '2:3'],
        3 => ['2:1:1', '1:2:1', '1:1:2', '1:2:2', '2:2:1'],
        4 => ['2:1:1:1', '1:1:1:2'],
    ];

    /**
     * Приводит присланное значение к допустимому. Пропорция, не совпадающая с
     * числом колонок (например «2:1» у трёх колонок), отбрасывается: применить
     * её нельзя, а тихо дорисовывать недостающие доли — значит показать
     * редактору не то, что он выбрал.
     */
    public static function normalize(string $ratio, int $columns): string
    {
        $ratio = trim($ratio);
        if ($ratio === '') {
            return '';
        }

        return in_array($ratio, self::OPTIONS[$columns] ?? [], true) ? $ratio : '';
    }

    /**
     * Значение для `grid-template-columns`. Пустая строка — если пропорция не
     * задана: равные колонки уже описаны в общей теме.
     */
    public static function template(string $ratio, int $columns): string
    {
        if (self::normalize($ratio, $columns) === '') {
            return '';
        }

        $parts = array_map(
            static fn (string $share): string => 'minmax(0, ' . (int) $share . 'fr)',
            explode(':', $ratio)
        );

        return implode(' ', $parts);
    }

    /**
     * Подпись варианта для формы: «2 : 1» читается лучше, чем «2:1».
     */
    public static function label(string $ratio): string
    {
        return implode(' : ', explode(':', $ratio));
    }
}
