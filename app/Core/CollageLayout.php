<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Типы сборки коллажа: готовые композиции вместо ручных номеров ячеек.
 *
 * Свободная сетка осталась, и она по-прежнему умолчание — вид собранных
 * страниц не меняется. Но в ней редактор задаёт четыре числа на каждый
 * элемент (колонка, ширина, строка, высота): для композиции из четырёх
 * элементов это шестнадцать чисел, а увидеть результат можно только сохранив
 * блок и открыв страницу. Тот же довод, по которому вариант отображения
 * показывается рисунком, а не называется словом.
 *
 * Пресет задаёт **и сетку, и места**: раскладка — это не украшение поверх
 * заданных ячеек, а ответ на вопрос «сколько колонок и строк нужно этой
 * композиции при таком числе элементов». Поэтому «Колонок» и «Строк» при
 * выбранном пресете не спрашиваются вовсе — иначе форма предлагала бы выбор,
 * который ничего не решает.
 *
 * Места считаются **от числа элементов**, а не берутся картинкой с
 * фиксированным набором: композиция из трёх элементов и из шести — это разные
 * сетки, а редактор добавляет элементы по одному и не обязан после каждого
 * подбирать раскладку заново.
 */
final class CollageLayout
{
    /** Свободная сетка: номера ячеек задаёт редактор. Умолчание. */
    public const FREE = 'free';

    /**
     * Подписи типов сборки. Ключ идёт в данные блока, поэтому список
     * объявляется один раз: схема полей, нормализатор и форма читают его
     * отсюда.
     *
     * @var array<string, string>
     */
    public const LAYOUTS = [
        self::FREE => 'Свободная сетка',
        'strip' => 'Ряд',
        'hero' => 'Главный кадр и спутники',
        'mosaic' => 'Мозаика',
        'stack' => 'С наложением',
    ];

    /**
     * Границы холста — те же, что принимает схема полей: «Колонок в сетке»
     * это выбор из 4/6/8/12, «Строк» — число от 2 до 8. Раскладка обязана
     * укладываться в них, иначе выходит тихий отказ: места посчитаны по
     * своей сетке, а вывод прогоняет данные через схему, та заменяет чужое
     * значение умолчанием — и композиция разъезжается. Замерено на «Ряде»:
     * сетка 3×1 превращалась на выводе в 6×2.
     */
    private const MIN_ROWS = 2;
    private const MAX_ROWS = 8;

    /** Ширина холста у всех пресетов. Шести колонок хватает и на треть, и на половину. */
    private const COLS = 6;

    /**
     * У «Ряда» колонок двенадцать: столько делится и на два, и на три, и на
     * четыре. На шести ряд из четырёх элементов не разложить без остатка, а
     * остаток — это столбец шире соседей.
     */
    private const STRIP_COLS = 12;

    /**
     * Сетка и места элементов для выбранного типа сборки.
     *
     * @return array{columns: int, rows: int, cells: list<array{col: int, col_span: int, row: int, row_span: int}>}
     */
    public static function place(string $layout, int $count): array
    {
        $count = max(0, $count);
        if ($count === 0) {
            return ['columns' => self::COLS, 'rows' => 2, 'cells' => []];
        }

        // Один элемент — это не композиция: любой пресет отдаёт ему холст
        // целиком. Иначе «Мозаика» из одного кадра оставляла бы полполотна
        // пустым, и это читалось бы как незагрузившийся снимок.
        if ($count === 1) {
            return [
                'columns' => self::COLS,
                'rows' => 2,
                'cells' => [['col' => 1, 'col_span' => self::COLS, 'row' => 1, 'row_span' => 2]],
            ];
        }

        return match ($layout) {
            'strip' => self::strip($count),
            'hero' => self::hero($count),
            'mosaic' => self::mosaic($count),
            'stack' => self::stack($count),
            default => ['columns' => self::COLS, 'rows' => 2, 'cells' => []],
        };
    }

    /** Пресет ли это (то есть места считаются, а не берутся из формы). */
    public static function isPreset(string $layout): bool
    {
        return $layout !== self::FREE && isset(self::LAYOUTS[$layout]);
    }

    /**
     * Ряд равных элементов. Самая простая композиция и потому самая частая:
     * четыре снимка одной высоты. Больше четырёх в строку не ставим — на
     * полотне 4:3 ячейка становится уже своего промежутка.
     *
     * @return array{columns: int, rows: int, cells: list<array{col: int, col_span: int, row: int, row_span: int}>}
     */
    private static function strip(int $count): array
    {
        $perRow = $count <= 4 ? $count : (int) ceil($count / 2);
        $perRow = min($perRow, 4);
        $step = intdiv(self::STRIP_COLS, $perRow);

        $textRows = (int) ceil($count / $perRow);
        $rows = min(self::MAX_ROWS, max(self::MIN_ROWS, $textRows));
        // Один ряд занимает обе строки холста: меньше двух строк схема не
        // принимает, а половина пустого полотна под рядом — это дыра.
        $rowSpan = $textRows === 1 ? $rows : 1;

        $cells = [];
        for ($i = 0; $i < $count; $i++) {
            $row = (int) floor($i / $perRow) + 1;
            if ($row > $rows) {
                break;
            }
            $col = ($i % $perRow) * $step + 1;
            // Последний элемент неполного ряда занимает остаток строки: дыра
            // в ряду карточек читается как незагрузившийся элемент, и то же
            // правило держит GridBalance у сеток блоков.
            $span = $i === $count - 1 ? self::STRIP_COLS - $col + 1 : $step;
            $cells[] = ['col' => $col, 'col_span' => $span, 'row' => $row, 'row_span' => $rowSpan];
        }

        return ['columns' => self::STRIP_COLS, 'rows' => $rows, 'cells' => $cells];
    }

    /**
     * Главный кадр и спутники. До трёх спутников они стоят колонкой справа —
     * взгляд входит в крупный кадр и спускается по мелким. Больше трёх в
     * колонку не помещаются (ячейка становится полосой в пару десятков
     * пикселей), поэтому главный кадр уходит наверх во всю ширину, а спутники
     * ложатся рядом под ним.
     *
     * @return array{columns: int, rows: int, cells: list<array{col: int, col_span: int, row: int, row_span: int}>}
     */
    private static function hero(int $count): array
    {
        $satellites = $count - 1;

        if ($satellites <= 3) {
            $rows = max(2, $satellites);
            $cells = [['col' => 1, 'col_span' => 4, 'row' => 1, 'row_span' => $rows]];
            // Спутников может быть меньше строк (двое при rows = 2 — ровно),
            // поэтому высота спутника считается делением, а остаток отдаётся
            // последнему: иначе внизу колонки оставалась бы пустая ячейка.
            $step = intdiv($rows, max(1, $satellites));
            for ($i = 0; $i < $satellites; $i++) {
                $row = $i * $step + 1;
                $span = $i === $satellites - 1 ? $rows - $row + 1 : $step;
                $cells[] = ['col' => 5, 'col_span' => 2, 'row' => $row, 'row_span' => max(1, $span)];
            }

            return ['columns' => self::COLS, 'rows' => $rows, 'cells' => $cells];
        }

        $perRow = 3;
        $tailRows = min(self::MAX_ROWS - 2, (int) ceil($satellites / $perRow));
        $rows = 2 + $tailRows;
        $cells = [['col' => 1, 'col_span' => self::COLS, 'row' => 1, 'row_span' => 2]];
        for ($i = 0; $i < $satellites; $i++) {
            $row = 3 + (int) floor($i / $perRow);
            if ($row > $rows) {
                break;
            }
            $col = ($i % $perRow) * 2 + 1;
            // Тот же запрет дыры, что и у ряда: хвост растягивается до края.
            $span = $i === $satellites - 1 ? self::COLS - $col + 1 : 2;
            $cells[] = ['col' => $col, 'col_span' => $span, 'row' => $row, 'row_span' => 1];
        }

        return ['columns' => self::COLS, 'rows' => $rows, 'cells' => $cells];
    }

    /**
     * Мозаика: пары «широкий + узкий», и стороны чередуются. Зигзаг не даёт
     * ряду читаться таблицей — ради этого коллаж и существует рядом с
     * «Медиагалереей», которая как раз таблица.
     *
     * @return array{columns: int, rows: int, cells: list<array{col: int, col_span: int, row: int, row_span: int}>}
     */
    private static function mosaic(int $count): array
    {
        $textRows = (int) ceil($count / 2);
        $rows = min(self::MAX_ROWS, max(self::MIN_ROWS, $textRows));
        // Как и у «Ряда»: единственная строка занимает обе — иначе нижняя
        // половина полотна остаётся пустой.
        $rowSpan = $textRows === 1 ? $rows : 1;
        $cells = [];
        for ($i = 0; $i < $count; $i++) {
            $row = (int) floor($i / 2) + 1;
            if ($row > $rows) {
                break;
            }
            $wideFirst = $row % 2 === 1;
            $isFirstInRow = $i % 2 === 0;

            // Нечётный последний элемент занимает строку целиком: узкая
            // плитка с пустотой рядом выглядит недогруженной страницей.
            if ($isFirstInRow && $i === $count - 1) {
                $cells[] = ['col' => 1, 'col_span' => self::COLS, 'row' => $row, 'row_span' => $rowSpan];
                continue;
            }

            $wide = $isFirstInRow === $wideFirst;
            $span = $wide ? 4 : 2;
            $col = $isFirstInRow ? 1 : ($wideFirst ? 5 : 3);
            $cells[] = ['col' => $col, 'col_span' => $span, 'row' => $row, 'row_span' => $rowSpan];
        }

        return ['columns' => self::COLS, 'rows' => $rows, 'cells' => $cells];
    }

    /**
     * С наложением: элементы идут по диагонали и заходят друг на друга. Это
     * единственная раскладка, ради которой размещение хранится номерами ячеек
     * — наложение получается тем, что соседи занимают общие ячейки, а кто
     * сверху, решает порядок в репитере.
     *
     * @return array{columns: int, rows: int, cells: list<array{col: int, col_span: int, row: int, row_span: int}>}
     */
    private static function stack(int $count): array
    {
        $rows = min(self::MAX_ROWS, $count + 1);
        $cells = [];
        for ($i = 0; $i < $count; $i++) {
            $row = min($rows, $i + 1);
            // Ширина чередуется, а колонка сдвигается вправо и возвращается:
            // ровная лесенка вниз-вправо уводит композицию из полотна, и
            // последние элементы упирались бы в правый край.
            $span = $i % 2 === 0 ? 4 : 3;
            $col = $i % 3 + 1;
            $col = min($col, self::COLS - $span + 1);
            $cells[] = [
                'col' => $col,
                'col_span' => $span,
                'row' => $row,
                'row_span' => min(2, $rows - $row + 1),
            ];
        }

        return ['columns' => self::COLS, 'rows' => $rows, 'cells' => $cells];
    }
}
