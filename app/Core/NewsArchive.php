<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Архив ленты новостей по году и месяцу (FUNC-6): `/news?year=2025&month=3`.
 *
 * Лента — четырнадцать карточек на страницу, и до новости полугодовой
 * давности приходилось листать десятки страниц. Архив даёт прямой адрес
 * каждому месяцу: им можно поделиться, и его индексирует поиск.
 *
 * Правила:
 * - **Годы и месяцы — только те, где новости есть** (на этом языке): пустой
 *   месяц в списке — это ссылка в никуда.
 * - **Значение из адреса сверяется со списком**, а не принимается как есть:
 *   `?year=1900` или `?month=13` не строят запрос, а сбрасываются к ленте.
 * - **Адрес собирается одной функцией** (`url`) — её зовут рубрикатор,
 *   архив и полоса страниц, иначе один из них потерял бы рубрику или месяц.
 */
final class NewsArchive
{
    /**
     * Выбранный период из адреса, если он есть среди доступных.
     *
     * @param array<int, list<int>> $available год → месяцы
     * @return array{year:int, month:int} 0 — не выбран
     */
    public static function selected(InputBag $query, array $available): array
    {
        $year = $query->int('year', 0, 0, 9999);
        $month = $query->int('month', 0, 0, 12);
        if ($year === 0 || !isset($available[$year])) {
            return ['year' => 0, 'month' => 0];
        }
        if ($month !== 0 && !in_array($month, $available[$year], true)) {
            $month = 0;
        }

        return ['year' => $year, 'month' => $month];
    }

    /**
     * Границы периода для запроса: `[начало, конец)`, или null — без фильтра.
     *
     * @return array{0:string, 1:string}|null
     */
    public static function period(int $year, int $month): ?array
    {
        if ($year <= 0) {
            return null;
        }
        if ($month >= 1 && $month <= 12) {
            $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $to = $month === 12
                ? sprintf('%04d-01-01 00:00:00', $year + 1)
                : sprintf('%04d-%02d-01 00:00:00', $year, $month + 1);

            return [$from, $to];
        }

        return [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1)];
    }

    /** Адрес ленты с фильтрами; пустые значения в адрес не идут. */
    public static function url(string $category = '', int $year = 0, int $month = 0, int $page = 1): string
    {
        $query = array_filter([
            'category' => $category,
            'year' => $year > 0 ? $year : null,
            'month' => $year > 0 && $month > 0 ? $month : null,
            'page' => $page > 1 ? $page : null,
        ], static fn ($v): bool => $v !== null && $v !== '');

        return Locale::url('news') . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** Название месяца на языке страницы (именительный падеж). */
    public static function monthName(int $month): string
    {
        $names = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
            7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return isset($names[$month]) ? t($names[$month]) : '';
    }
}
