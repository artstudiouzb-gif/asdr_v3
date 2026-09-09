<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ряды карточек без дыр.
 *
 * Обычная сетка оставляет в последнем ряду пустые ячейки: пять карточек в три
 * колонки дают 3+2 и провал справа шириной в треть экрана. Здесь колонка
 * дробится так, чтобы остаток делился нацело: сетка получает
 * `columns × остаток` дорожек, обычная карточка занимает `остаток` дорожек, а
 * карточки последнего ряда — `columns`. В итоге оба ряда заполнены целиком.
 *
 * Отдаёт scoped CSS (его собирает BlockRenderer), поэтому инлайн-стилей в
 * разметке не появляется.
 */
final class GridBalance
{
    /** Ниже этой ширины раскладку задаёт мобильный медиазапрос темы. */
    private const DESKTOP_MIN_WIDTH = 901;

    /**
     * @param string $gridSelector CSS-селектор сетки внутри блока (без #block-N)
     * @param string $itemSelector CSS-селектор карточки внутри блока
     */
    public static function css(
        int $blockId,
        string $gridSelector,
        string $itemSelector,
        int $count,
        int $columns
    ): string {
        $columns = max(1, $columns);
        $count = max(0, $count);
        if ($count === 0) {
            return '';
        }

        $scope = '#block-' . $blockId . ' ';
        $remainder = $count % $columns;

        // Одинокий хвост не растягиваем: карточка во всю ширину ряда читается
        // как ошибка вёрстки, а не как приём — пустой слот справа спокойнее.
        // Автоподбор колонок (columnsFor) до такого случая не доводит, остаток
        // в одну карточку возможен только при выборе колонок вручную.
        if ($remainder === 0 || $remainder === 1 || $count <= $columns) {
            // Ряды и так заполнены: одна дорожка на карточку.
            return $scope . $gridSelector . '{--grid-track:' . min($count, $columns) . ';--grid-span:1}';
        }

        $track = $columns * $remainder;

        return $scope . $gridSelector . '{--grid-track:' . $track . ';--grid-span:' . $remainder . '}'
            . '@media (min-width:' . self::DESKTOP_MIN_WIDTH . 'px){'
            . $scope . $itemSelector . ':nth-last-child(-n+' . $remainder . ')'
            . '{grid-column:span ' . $columns . '}}';
    }

    /**
     * Сколько колонок брать под $count карточек, чтобы в последнем ряду не
     * осталась одинокая карточка: пятёрка ложится 3+2, а не 4+1.
     */
    public static function columnsFor(int $count, int $max = 4): int
    {
        if ($count <= 0) {
            return 1;
        }
        if ($count <= 3) {
            return min($count, $max);
        }
        for ($columns = $max; $columns >= 2; $columns--) {
            if ($count % $columns !== 1) {
                return $columns;
            }
        }

        return $max;
    }
}
