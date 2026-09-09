<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Разбор данных диаграммы: одна строка — «Подпись | Значение».
 *
 * Формат тот же, что у блока «Таблица»: редактор вставляет данные из документа
 * и правит их как текст. Вложенный репитер здесь был бы вдвойне неудобен —
 * значений в диаграмме обычно больше, чем полей в форме.
 *
 * Значение принимается и с запятой («5,6»), и с пробелами разрядов («12 400»):
 * так его набирают в русских документах, и требовать точку значило бы просить
 * редактора переписать источник.
 */
final class ChartData
{
    /**
     * Слотов ровно шесть, и это не округление «на глаз»: адресуемых цветов,
     * различимых при дальтонизме, в наборе шесть (порядок и проверка — в
     * public/assets/css/blocks/chart.css). Седьмая и дальше доли сливаются в
     * «Прочее»: сгенерированный седьмой оттенок неотличим от уже занятого.
     */
    public const MAX_SERIES = 6;

    private const TAIL_LABEL = 'Прочее';

    /**
     * @return array{rows: list<array{label: string, value: float, share: float}>, total: float, max: float}
     */
    public static function parse(string $source, string $variant = 'bars', float $total = 0.0): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $source) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);
            $label = trim($parts[0] ?? '');
            $value = self::number($parts[1] ?? '');
            // Строка без числа диаграммой не рисуется: подпись без значения
            // дала бы полосу нулевой длины, то есть пустое место с текстом.
            if ($label === '' || $value === null) {
                continue;
            }
            $rows[] = ['label' => $label, 'value' => $value];
        }

        if ($rows === []) {
            return ['rows' => [], 'total' => 0.0, 'max' => 0.0];
        }

        if ($variant === 'stacked') {
            $rows = self::foldTail($rows);
        }

        // `array_column()` молча пропускает строку без ключа `value`, поэтому
        // на испорченных данных набор может оказаться пустым — а `max()` от
        // пустого массива это ValueError, то есть 500 на странице вместо
        // диаграммы. Ноль здесь безопасен: ниже он даёт базу расчёта 1.
        $values = array_column($rows, 'value');
        $sum = array_sum($values);
        $max = $values === [] ? 0.0 : max($values);

        // База расчёта: у долей это сумма, у показателя к цели — 100 %,
        // у столбцов — самое большое значение (самая длинная полоса = вся ширина).
        $base = match (true) {
            $total > 0 => $total,
            $variant === 'stacked' => $sum,
            $variant === 'meter' => 100.0,
            default => $max,
        };

        $rows = array_map(
            static fn (array $row): array => $row + [
                'share' => $base > 0 ? min(100.0, round($row['value'] / $base * 100, 2)) : 0.0,
            ],
            $rows
        );

        return ['rows' => array_values($rows), 'total' => $base, 'max' => $max];
    }

    /**
     * Хвост сверх шести долей складывается в «Прочее» одной долей.
     *
     * @param list<array{label: string, value: float}> $rows
     * @return list<array{label: string, value: float}>
     */
    private static function foldTail(array $rows): array
    {
        if (count($rows) <= self::MAX_SERIES) {
            return $rows;
        }

        $head = array_slice($rows, 0, self::MAX_SERIES - 1);
        $tail = array_slice($rows, self::MAX_SERIES - 1);
        $head[] = ['label' => self::TAIL_LABEL, 'value' => array_sum(array_column($tail, 'value'))];

        return $head;
    }

    /** Число из строки документа: запятая как разделитель дробной части, пробелы разрядов. */
    private static function number(string $raw): ?float
    {
        $raw = str_replace([' ', "\u{00A0}", "\u{2009}"], '', trim($raw));
        $raw = str_replace(',', '.', $raw);
        if ($raw === '' || preg_match('/^-?\d+(?:\.\d+)?$/', $raw) !== 1) {
            return null;
        }

        return (float) $raw;
    }

    /** Доля для показа: без хвоста «,0» и с запятой, как принято в русском тексте. */
    public static function formatNumber(float $value): string
    {
        $rounded = round($value, 1);

        return $rounded === floor($rounded)
            ? number_format($rounded, 0, ',', "\u{00A0}")
            : number_format($rounded, 1, ',', "\u{00A0}");
    }
}
