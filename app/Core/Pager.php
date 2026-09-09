<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Полоса страниц: окно номеров вокруг текущей страницы и ссылки prev/next.
 *
 * Раньше номера выводились подряд: при сорока страницах полоса занимала три
 * ряда и превращалась в стену цифр. Теперь показываем края, окно вокруг
 * текущей и многоточия на разрывах — привычный вид «‹ 1 … 4 5 6 … 40 ›».
 *
 * Заодно здесь считаются адреса соседних страниц: их ждёт `<link rel="prev">`
 * в шапке — подсказка поисковику, что страницы связаны, а не дублируют друг
 * друга.
 */
final class Pager
{
    /** Сколько номеров показываем по обе стороны от текущего. */
    private const AROUND = 1;

    /**
     * Элементы полосы: номера страниц и разрывы.
     *
     * @return list<int|null> null — многоточие
     */
    public static function items(int $page, int $pages, int $around = self::AROUND): array
    {
        $pages = max(1, $pages);
        $page = max(1, min($page, $pages));

        $show = [1, $pages];
        for ($i = $page - $around; $i <= $page + $around; $i++) {
            if ($i >= 1 && $i <= $pages) {
                $show[] = $i;
            }
        }
        $show = array_values(array_unique($show));
        sort($show);

        $items = [];
        $previous = 0;
        foreach ($show as $number) {
            // Разрыв в одну страницу многоточием не прячем: «1 … 3» занимает
            // столько же места, сколько «1 2 3», но требует лишнего клика.
            if ($previous > 0 && $number - $previous === 2) {
                $items[] = $previous + 1;
            } elseif ($previous > 0 && $number - $previous > 2) {
                $items[] = null;
            }
            $items[] = $number;
            $previous = $number;
        }

        return $items;
    }

    /**
     * Адреса соседних страниц для `<link rel="prev|next">`.
     *
     * @param callable(int): string $url
     * @return array{prev: string, next: string}
     */
    public static function relLinks(int $page, int $pages, callable $url): array
    {
        $pages = max(1, $pages);
        $page = max(1, min($page, $pages));

        return [
            'prev' => $page > 1 ? $url($page - 1) : '',
            'next' => $page < $pages ? $url($page + 1) : '',
        ];
    }
}
