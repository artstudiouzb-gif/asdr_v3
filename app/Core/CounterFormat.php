<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Расчёты блока «Показатели», которые нужны и шаблону, и тестам: сколько
 * колонок в ряду, умеет ли число отсчитываться и куда направлено изменение.
 */
final class CounterFormat
{
    /** Виды, у которых число колонок — настройка. */
    public const COLUMN_VARIANTS = ['row', 'cards', 'list', 'progress'];

    /** Колонок в ряду не больше шести: в седьмой колонке ячейка становится уже подписи. */
    public const MAX_COLUMNS = 6;

    /** Строк не больше пяти: дальше это таблица, а не показатели. */
    public const MAX_ROWS = 5;

    /** Показателей в блоке — полная сетка, 6 × 5. */
    public const MAX_ITEMS = self::MAX_COLUMNS * self::MAX_ROWS;

    /** Тон изменения: цвет говорит «хорошо» или «плохо», а не «вверх» или «вниз». */
    public const TONES = ['neutral', 'good', 'bad'];

    /**
     * Колонок в ряду.
     *
     * При `auto-fit` пять показателей ложились 4 + 1, и одинокое число во
     * втором ряду читалось как ошибка вёрстки — тот же довод, что у
     * GridBalance. «Авто» выбирает деление без хвоста в одну ячейку: пять —
     * это 3 + 2, семь — 4 + 3. Строк при этом не больше пяти: двадцать
     * показателей — это 4 × 5, а не 3 × 7, поэтому колонок берётся не меньше,
     * чем нужно на пять строк. Список держит одну или две колонки: в третьей
     * строка «число — подпись» становится уже самой подписи.
     */
    public static function columns(string $variant, int $requested, int $count): int
    {
        $count = max(1, $count);
        if ($variant === 'list') {
            return $requested >= 2 && $count > 1 ? 2 : 1;
        }
        if ($requested >= 2) {
            return min($requested, self::MAX_COLUMNS, $count);
        }
        if ($count <= 4) {
            return $count;
        }
        // Меньше колонок, чем нужно на пять строк, брать нельзя.
        $least = (int) ceil(min($count, self::MAX_ITEMS) / self::MAX_ROWS);
        // Сначала обычные четыре или три колонки, и только если в них нет
        // ровного ряда или ряда без одиночки — пять и шесть: пять показателей
        // это 3 + 2, а не пять в строку.
        $allowed = static fn (array $set): array => array_values(array_filter($set, static fn (int $cols): bool => $cols >= $least));
        foreach ([$allowed([4, 3]), $allowed([5, 6])] as $options) {
            // Сначала ровные ряды (шесть — это 3 + 3, а не 4 + 2), потом
            // любые без одиночки в хвосте.
            foreach ($options as $cols) {
                if ($count % $cols === 0) {
                    return $cols;
                }
            }
            foreach ($options as $cols) {
                if ($count % $cols !== 1) {
                    return $cols;
                }
            }
        }

        return max(4, min($least, self::MAX_COLUMNS));
    }

    /**
     * Хвост ряда во всю ширину.
     *
     * Пять показателей в три колонки — это ряд из трёх и ряд из двух, и
     * рядом со вторым оставалась пустая ячейка: у карточек она читается как
     * незагрузившийся элемент. Сетка берётся мельче — наименьшее общее
     * кратное колонок и хвоста, — и хвост получает шаг крупнее: три по две
     * дорожки и две по три из шести.
     *
     * @return array{tracks: int, span: int, tail_span: int, tail: int}|null
     */
    public static function balance(int $columns, int $count): ?array
    {
        $tail = $columns > 1 ? $count % $columns : 0;
        if ($tail === 0 || $count <= $columns) {
            return null;
        }
        $a = $columns;
        $b = $tail;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $tracks = intdiv($columns * $tail, $a);

        return ['tracks' => $tracks, 'span' => intdiv($tracks, $columns), 'tail_span' => intdiv($tracks, $tail), 'tail' => $tail];
    }

    /**
     * Можно ли отсчитать число при появлении, и как его потом напечатать.
     *
     * Прежде отсчёт работал только для голых цифр: «1 200» теряло разряды,
     * а «4,5» не анимировалось вовсе, хотя именно так числа и пишут.
     * Скрипт получает само число и правила печати (знаков после запятой,
     * разделитель дроби, разделение разрядов) и в конце отсчёта выводит
     * ровно ту строку, что набрал редактор.
     *
     * @return array{target: string, decimals: int, separator: string, grouped: bool}|null
     */
    public static function countable(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('/^\d{1,9}$/', $value) === 1) {
            return ['target' => $value, 'decimals' => 0, 'separator' => '', 'grouped' => false];
        }
        // Разряды разделены неразрывным пробелом — его ставит нормализатор.
        if (preg_match('/^\d{1,3}(?:[\x{00A0} ]\d{3}){1,2}$/u', $value) === 1) {
            return ['target' => (string) preg_replace('/\D/', '', $value), 'decimals' => 0, 'separator' => '', 'grouped' => true];
        }
        if (preg_match('/^(\d{1,7})([.,])(\d{1,2})$/', $value, $m) === 1) {
            return ['target' => $m[1] . '.' . $m[3], 'decimals' => strlen($m[3]), 'separator' => $m[2], 'grouped' => false];
        }

        return null;
    }

    /**
     * Направление изменения по его записи: «+12 %», «−3», «↓ 5 п.п.».
     * Отдельного поля «вверх / вниз» нет — оно повторяло бы знак, который
     * редактор и так пишет, и расходилось бы с ним.
     */
    public static function direction(string $delta): string
    {
        $delta = ltrim($delta);
        if ($delta === '') {
            return '';
        }
        $first = mb_substr($delta, 0, 1);
        if (in_array($first, ['+', '↑', '▲'], true)) {
            return 'up';
        }
        if (in_array($first, ['-', '−', '–', '↓', '▼'], true)) {
            return 'down';
        }

        return '';
    }
}
