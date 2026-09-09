<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Месячная сетка календаря (чистые функции, без БД): недели по 7 дней с
 * понедельника, метки месяца по-русски, навигация на соседние месяцы.
 */
final class CalendarGrid
{
    private const MONTHS = [
        'ru' => [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'],
        'uz' => [1 => 'Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'Iyun', 'Iyul', 'Avgust', 'Sentabr', 'Oktabr', 'Noyabr', 'Dekabr'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    private const WEEKDAYS = [
        'ru' => ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
        'uz' => ['Du', 'Se', 'Ch', 'Pa', 'Ju', 'Sh', 'Ya'],
        'en' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    ];

    /**
     * Разбирает параметр месяца «YYYY-MM»; при мусоре — текущий месяц.
     *
     * @return array{0: int, 1: int} [год, месяц]
     */
    public static function parseMonth(string $raw): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($raw), $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            if ($year >= 1970 && $year <= 2100 && $month >= 1 && $month <= 12) {
                return [$year, $month];
            }
        }

        return [(int) date('Y'), (int) date('n')];
    }

    /**
     * Сетка месяца: массив недель, в каждой 7 ячеек
     * ['day' => int|null, 'date' => 'Y-m-d'|null] (null — дни соседних месяцев).
     *
     * @return array<int, array<int, array{day: ?int, date: ?string}>>
     */
    public static function build(int $year, int $month): array
    {
        $first = mktime(0, 0, 0, $month, 1, $year);
        if ($first === false) {
            // Год вне диапазона — сетки нет. Прежде false уходил в date() и
            // молча превращался в январь 1970-го.
            return [];
        }
        $daysInMonth = (int) date('t', $first);
        $startWeekday = (int) date('N', $first); // 1 = Пн

        $cells = [];
        for ($i = 1; $i < $startWeekday; $i++) {
            $cells[] = ['day' => null, 'date' => null];
        }
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $cells[] = ['day' => $d, 'date' => sprintf('%04d-%02d-%02d', $year, $month, $d)];
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = ['day' => null, 'date' => null];
        }

        return array_chunk($cells, 7);
    }

    /** «Июль 2026». */
    public static function label(int $year, int $month, string $lang = 'ru'): string
    {
        $months = self::MONTHS[$lang] ?? self::MONTHS['ru'];
        return ($months[$month] ?? '') . ' ' . $year;
    }

    /** @return list<string> */
    public static function weekdays(string $lang = 'ru'): array
    {
        return self::WEEKDAYS[$lang] ?? self::WEEKDAYS['ru'];
    }

    /** «YYYY-MM» соседнего месяца: $delta = -1 (пред.) или +1 (след.). */
    public static function shiftMonth(int $year, int $month, int $delta): string
    {
        $ts = mktime(0, 0, 0, $month + $delta, 1, $year);

        return $ts === false ? sprintf('%04d-%02d', $year, $month) : date('Y-m', $ts);
    }

    /**
     * Группирует записи по дате из JSON-поля данных.
     *
     * @param array<int, array<string, mixed>> $entries записи content_entries
     * @return array<string, array<int, array<string, mixed>>> 'Y-m-d' => записи
     */
    public static function groupByDate(array $entries, string $fieldName, int $year, int $month): array
    {
        $prefix = sprintf('%04d-%02d-', $year, $month);
        $grouped = [];
        foreach ($entries as $entry) {
            $data = is_array($entry['data'] ?? null)
                ? $entry['data']
                : (json_decode((string) ($entry['data'] ?? ''), true) ?: []);
            $date = trim((string) ($data[$fieldName] ?? ''));
            if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            if (str_starts_with($date, $prefix)) {
                $entry['data'] = $data;
                $grouped[$date][] = $entry;
            }
        }
        ksort($grouped);

        return $grouped;
    }
}
