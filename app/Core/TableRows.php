<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Разбор таблицы, набранной построчно: одна строка — ряд, ячейки через `|`.
 *
 * Репитер «ряд с вложенным репитером ячеек» в форме нечитаем: чтобы поправить
 * одно число в таблице 6×8, редактору пришлось бы разворачивать сорок восемь
 * полей. Построчная запись — тот же приём, что у оргструктуры и у блока
 * «Иконка и текст»: таблица видна целиком, её можно вставить из документа и
 * поправить как текст.
 *
 * Ряды выравниваются по самому длинному: короткий ряд добивается пустыми
 * ячейками, а лишние в длинном не отбрасываются — иначе таблица молча теряла
 * бы данные, набранные редактором.
 */
final class TableRows
{
    /** Больше редактор в форму не наберёт, а страница не должна собираться вечно. */
    private const MAX_ROWS = 200;

    /** @var int Столбцов сверх этого в таблице на странице не бывает. */
    private const MAX_COLS = 20;

    /**
     * @return list<list<string>> ряды одинаковой длины; пустой массив, если рядов нет
     */
    public static function parse(string $source): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $source) ?: [] as $line) {
            $line = trim((string) $line);
            // Пустая строка — не пустой ряд: так редактор разделяет куски
            // таблицы при наборе, и пустая полоса посреди неё выглядела бы
            // ошибкой вёрстки.
            if ($line === '') {
                continue;
            }

            $cells = array_map('trim', explode('|', $line));
            if (count($cells) > self::MAX_COLS) {
                $cells = array_slice($cells, 0, self::MAX_COLS);
            }
            $rows[] = $cells;
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        if ($rows === []) {
            return [];
        }

        $width = max(array_map('count', $rows));

        return array_map(
            static fn (array $cells): array => array_pad($cells, $width, ''),
            $rows
        );
    }

    /** Число столбцов — нужно шаблону для scoped CSS. */
    public static function width(array $rows): int
    {
        return $rows === [] ? 0 : count($rows[0]);
    }
}
