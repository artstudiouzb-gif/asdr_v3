<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\BlockData\CollageBlockNormalizer;

/**
 * Ансамбли «Коллажа»: композиции на четыре–семь элементов плюс печать.
 *
 * Прежние композиции по ролям держат два-четыре элемента: кадр, карточка в
 * вырезе, печать, узор. Коллаж на госсайте чаще собирается крупнее — снимок
 * объекта, ролик, два-три показателя и слово руководителя, — и такой набор
 * либо не помещался в них вовсе (лишнее не выводилось), либо уходил в
 * свободную сетку с шестнадцатью числами размещения.
 *
 * **Места выбираются по типу элемента, а не по номеру в списке.** Каждая
 * раскладка на каждое число элементов объявлена набором прямоугольников с
 * формой («ведущий», «широкий», «высокий», «малый», «полоса»), и элементы
 * расходятся по ним так, чтобы каждому досталась подходящая форма: цитате —
 * широкая плитка, где строка не рвётся на трёх словах, числу — малая, кадру —
 * высокая. Перебирается всё: элементов не больше семи, вариантов расстановки
 * остальных шести — 720, это доли миллисекунды. Жадный проход по списку
 * здесь не годится: второй кадр, стоящий в списке раньше цитаты, забирал бы
 * её широкую плитку, а цитата ужималась бы в малую.
 *
 * **Ведущий — первый кадр** (фотография или видео), где бы он ни стоял в
 * списке: композиция строится вокруг него, и число в плитке 2×2 читалось бы
 * баннером. Кадра нет — ведущим становится первый элемент.
 *
 * **Раскладка приходит переменными, а не свойствами** (`--cols`, `--rows`,
 * `--ga` в scoped CSS). Правила scoped CSS весят по id, и узкий экран не смог
 * бы их отменить без флага приоритета; переменную же читает правило темы
 * блока, и узкий вариант просто перестаёт её читать.
 *
 * **Узкий вариант считается здесь же** (`full`): на телефоне сетка в две
 * колонки, и одиночная плитка в конце ряда оставляла бы дыру — её
 * растягивают на ряд, как хвост у GridBalance.
 */
final class CollageEnsemble
{
    /** Раскладки-ансамбли. Подписи — в CollageLayout::LAYOUTS. */
    public const LAYOUTS = ['bento', 'stage', 'panorama', 'cascade'];

    /**
     * Сколько элементов (без печати) принимает ансамбль. Больше семи плиток
     * — это уже галерея: у каждой становится слишком мало места, и
     * композиция распадается на таблицу.
     */
    public const CAPACITY = 7;

    /**
     * Во всю ширину телефона: цитата и справка (в половине экрана строка
     * рвётся на двух словах), узор-полоса и видео — подпись ролика плашкой
     * внизу и кнопка посередине в половине телефона накрывали друг друга.
     *
     * @var list<string>
     */
    private const NARROW_WIDE = ['quote', 'info', 'pattern', 'video'];

    /** Кадры: вокруг них строится композиция. */
    public const MEDIA = ['photo', 'video'];

    /**
     * Цена формы для типа элемента: чем меньше, тем уместнее. «Ведущий» здесь
     * не встречается — его место выбирается отдельно.
     */
    private const COST = [
        'media' => ['tall' => 0, 'wide' => 1, 'band' => 3, 'small' => 2],
        'text' => ['tall' => 1, 'wide' => 0, 'band' => 0, 'small' => 3],
        'stat' => ['tall' => 1, 'wide' => 1, 'band' => 2, 'small' => 0],
        'deco' => ['tall' => 1, 'wide' => 2, 'band' => 3, 'small' => 0],
    ];

    /**
     * Прямоугольники раскладок: [форма, строка-начало, колонка-начало,
     * строка-конец, колонка-конец] — номера линий сетки, как в grid-area.
     * Первый — всегда ведущий.
     *
     * «Кадр и плитки» — сетка в четыре колонки, ведущий 2×2 слева.
     * «Кадр в центре» — те же четыре колонки, но боковые уже (5:7:7:5), и
     * ведущий стоит посередине: композиция симметрична, это официальный вид.
     *
     * @var array<string, array<int, list<array{0: string, 1: int, 2: int, 3: int, 4: int}>>>
     */
    private const AREAS = [
        'bento' => [
            1 => [['lead', 1, 1, 3, 5]],
            2 => [['lead', 1, 1, 3, 4], ['tall', 1, 4, 3, 5]],
            3 => [['lead', 1, 1, 3, 3], ['wide', 1, 3, 2, 5], ['wide', 2, 3, 3, 5]],
            4 => [['lead', 1, 1, 3, 3], ['small', 1, 3, 2, 4], ['small', 1, 4, 2, 5], ['wide', 2, 3, 3, 5]],
            5 => [['lead', 1, 1, 3, 3], ['small', 1, 3, 2, 4], ['small', 1, 4, 2, 5], ['small', 2, 3, 3, 4], ['small', 2, 4, 3, 5]],
            6 => [['lead', 1, 1, 3, 3], ['wide', 1, 3, 2, 5], ['small', 2, 3, 3, 4], ['tall', 2, 4, 4, 5], ['wide', 3, 1, 4, 3], ['small', 3, 3, 4, 4]],
            7 => [['lead', 1, 1, 3, 3], ['small', 1, 3, 2, 4], ['tall', 1, 4, 3, 5], ['small', 2, 3, 3, 4], ['small', 3, 1, 4, 2], ['wide', 3, 2, 4, 4], ['small', 3, 4, 4, 5]],
        ],
        'stage' => [
            1 => [['lead', 1, 1, 3, 5]],
            2 => [['lead', 1, 1, 3, 4], ['tall', 1, 4, 3, 5]],
            3 => [['lead', 1, 2, 3, 4], ['tall', 1, 1, 3, 2], ['tall', 1, 4, 3, 5]],
            4 => [['lead', 1, 2, 3, 4], ['small', 1, 1, 2, 2], ['small', 2, 1, 3, 2], ['tall', 1, 4, 3, 5]],
            5 => [['lead', 1, 2, 3, 4], ['small', 1, 1, 2, 2], ['small', 2, 1, 3, 2], ['small', 1, 4, 2, 5], ['small', 2, 4, 3, 5]],
            6 => [['lead', 1, 2, 3, 4], ['small', 1, 1, 2, 2], ['small', 2, 1, 3, 2], ['small', 1, 4, 2, 5], ['small', 2, 4, 3, 5], ['band', 3, 1, 4, 5]],
            7 => [['lead', 1, 2, 3, 4], ['small', 1, 1, 2, 2], ['small', 2, 1, 3, 2], ['small', 1, 4, 2, 5], ['small', 2, 4, 3, 5], ['wide', 3, 1, 4, 3], ['wide', 3, 3, 4, 5]],
        ],
    ];

    /**
     * Панорама: кадр во всю ширину и ряд карточек, заходящий на его нижний
     * край. Полоса карточек — двенадцать колонок между двумя полями
     * (`--pano-inset`): двенадцать делится и на два, и на три, и на четыре.
     * Больше четырёх карточек в ряд не ставим — у пятой становится уже
     * текста, — поэтому пятая и шестая уходят вторым рядом под первым.
     *
     * @var array<int, list<array{0: string, 1: int}>> ряды карточек: [форма, ширина в колонках полосы]
     */
    private const PANORAMA_CARDS = [
        0 => [],
        1 => [['wide', 4]],
        2 => [['wide', 6], ['wide', 6]],
        3 => [['small', 4], ['small', 4], ['small', 4]],
        4 => [['small', 3], ['small', 3], ['small', 3], ['small', 3]],
        5 => [['small', 4], ['small', 4], ['small', 4], ['wide', 6], ['wide', 6]],
        6 => [['small', 4], ['small', 4], ['small', 4], ['small', 4], ['small', 4], ['small', 4]],
    ];

    /** Раскладка — ансамбль. */
    public static function isEnsemble(string $layout): bool
    {
        return in_array($layout, self::LAYOUTS, true);
    }

    /**
     * Раскладка элементов.
     *
     * `tiles` идут в порядке вывода: ведущий первым, остальные — как в
     * списке редактора. Тот же порядок у узкого варианта и у диктора.
     *
     * @param list<array<string, mixed>> $items
     * @return array{
     *     complete: bool,
     *     columns: string,
     *     rows: string,
     *     tiles: list<array{index: int, area: string, shape: string, full: bool, by_badge: string, ratio: string, column: int}>,
     *     badge: array{index: int, area: string, at: string}|null,
     *     unused: list<int>
     * }
     */
    public static function plan(string $layout, array $items): array
    {
        $badge = null;
        $tiles = [];
        $unused = [];
        foreach ($items as $index => $item) {
            if ($badge === null && ($item['type'] ?? '') === 'badge') {
                $badge = $index;
                continue;
            }
            if (count($tiles) >= self::CAPACITY) {
                $unused[] = $index;
                continue;
            }
            $tiles[] = $index;
        }

        $lead = null;
        foreach ($tiles as $index) {
            if (in_array((string) ($items[$index]['type'] ?? ''), self::MEDIA, true)) {
                $lead = $index;
                break;
            }
        }

        $plan = [
            // Панораме без кадра нечем быть: полоса числа во всю ширину — это
            // баннер, а не композиция. Остальные собираются и из одних карточек.
            'complete' => $tiles !== [] && ($layout !== 'panorama' || $lead !== null),
            'columns' => '',
            'rows' => '',
            'tiles' => [],
            'badge' => null,
            'unused' => $unused,
        ];
        if (!$plan['complete']) {
            return $plan;
        }

        $lead ??= $tiles[0];
        $rest = array_values(array_filter($tiles, static fn (int $i): bool => $i !== $lead));

        if ($layout === 'cascade') {
            return self::cascade($plan, $items, $tiles, $badge);
        }

        [$columns, $rows, $areas, $badgeAt] = $layout === 'panorama'
            ? self::panoramaAreas(count($rest))
            : self::gridAreas($layout, count($tiles));

        $plan['columns'] = $columns;
        $plan['rows'] = $rows;
        $assigned = self::assign($items, $rest, array_slice($areas, 1));

        $order = [$lead, ...$rest];
        $placed = [];
        foreach ($order as $n => $index) {
            $rect = $n === 0 ? $areas[0] : $assigned[$index];
            $placed[] = ['index' => $index, 'rect' => $rect];
        }

        // Точка печати: середина края ведущего, где сходятся соседние плитки.
        // Плитки, чей край проходит через неё, получают отступ со стороны
        // печати — иначе она ложилась бы на знак цитаты или начало строки.
        $point = null;
        if ($badge !== null && in_array($badgeAt, ['start', 'end'], true)) {
            $leadRect = $areas[0];
            $col = $badgeAt === 'start' ? $leadRect[2] : $leadRect[4];
            $row = ($leadRect[1] + $leadRect[3]) / 2;
            $point = [$row, $col];
        }

        $full = self::narrowFull($items, array_column($placed, 'index'), $lead);
        foreach ($placed as ['index' => $index, 'rect' => $rect]) {
            $byBadge = '';
            if ($point !== null && $point[0] >= $rect[1] && $point[0] <= $rect[3]) {
                if ($rect[2] === $point[1]) {
                    $byBadge = 'start';
                } elseif ($rect[4] === $point[1]) {
                    $byBadge = 'end';
                }
            }
            $plan['tiles'][] = [
                'index' => $index,
                'area' => $rect[1] . '/' . $rect[2] . '/' . $rect[3] . '/' . $rect[4],
                'shape' => $rect[0],
                'full' => isset($full[$index]),
                'by_badge' => $index === $lead ? '' : $byBadge,
                'ratio' => '',
                'column' => 0,
            ];
        }

        if ($badge !== null) {
            $leadRect = $areas[0];
            $plan['badge'] = [
                'index' => $badge,
                'area' => $leadRect[1] . '/' . $leadRect[2] . '/' . $leadRect[3] . '/' . $leadRect[4],
                'at' => $badgeAt,
            ];
        }

        return $plan;
    }

    /**
     * @return array{0: string, 1: string, 2: list<array{0: string, 1: int, 2: int, 3: int, 4: int}>, 3: string}
     */
    private static function gridAreas(string $layout, int $count): array
    {
        $count = max(1, min(self::CAPACITY, $count));
        $areas = self::AREAS[$layout][$count];
        $rows = max(array_map(static fn (array $rect): int => $rect[3], $areas)) - 1;

        $columns = $layout === 'stage'
            ? 'minmax(0,5fr) minmax(0,7fr) minmax(0,7fr) minmax(0,5fr)'
            : 'repeat(4,minmax(0,1fr))';

        // Печать у «Кадра и плиток» встаёт на правый край ведущего, где
        // сходятся плитки. У «Кадра в центре» — над кадром посередине: на
        // боковом крае она садилась на карточки шириной в пятую часть
        // полотна и сжимала их текст до двух слов в строке, а симметричной
        // композиции симметричное место и нужно. Ведущий во всю ширину
        // печать на край не ставит: половина её вышла бы за блок.
        $at = match (true) {
            $areas[0][4] >= 5 => 'top-end',
            $layout === 'stage' && $areas[0][2] > 1 => 'top-center',
            default => 'end',
        };

        return [$columns, 'repeat(' . $rows . ',minmax(var(--row),auto))', $areas, $at];
    }

    /**
     * @return array{0: string, 1: string, 2: list<array{0: string, 1: int, 2: int, 3: int, 4: int}>, 3: string}
     */
    private static function panoramaAreas(int $cards): array
    {
        $cards = max(0, min(self::CAPACITY - 1, $cards));
        $spec = self::PANORAMA_CARDS[$cards];
        // Колонки: поле, двенадцать колонок полосы, поле. Линии полосы — со
        // второй по четырнадцатую.
        $columns = 'var(--pano-inset) repeat(12,minmax(0,1fr)) var(--pano-inset)';

        if ($cards === 0) {
            return [$columns, 'var(--pano-h)', [['lead', 1, 1, 2, 15]], 'top-end'];
        }

        $areas = [['lead', 1, 1, 3, 15]];
        $line = 2;
        $row = 2;
        $used = 0;
        foreach ($spec as [$shape, $span]) {
            // Второй ряд начинается, когда первый заполнен (двенадцать колонок).
            if ($used + $span > 12) {
                $row = 4;
                $line = 2;
                $used = 0;
            }
            // Одна карточка стоит у правого края, а не у левого: взгляд входит
            // в кадр слева и выходит на число.
            if ($cards === 1) {
                $line = 14 - $span;
            }
            $areas[] = [$shape, $row, $line, $row === 2 ? 4 : 5, $line + $span];
            $line += $span;
            $used += $span;
        }

        // Первый ряд карточек занимает две строки: нижнюю полосу кадра
        // (`--pano-lift`) и продолжение под ним.
        $rows = 'calc(var(--pano-h) - var(--pano-lift)) var(--pano-lift) minmax(var(--pano-card),auto)'
            . ($row === 4 ? ' minmax(var(--row),auto)' : '');

        return [$columns, $rows, $areas, 'top-end'];
    }

    /**
     * Расстановка остальных элементов по местам с наименьшей суммарной ценой
     * формы. При равной цене побеждает первая перестановка — то есть ранние
     * в списке элементы получают ранние места, и порядок редактора
     * сохраняется, где форма ему не мешает.
     *
     * @param list<array<string, mixed>> $items
     * @param list<int> $rest
     * @param list<array{0: string, 1: int, 2: int, 3: int, 4: int}> $slots
     * @return array<int, array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    private static function assign(array $items, array $rest, array $slots): array
    {
        $best = null;
        $bestCost = PHP_INT_MAX;
        foreach (self::permutations(array_keys($slots)) as $perm) {
            $cost = 0;
            foreach ($rest as $n => $index) {
                $cost += self::COST[self::kind((string) ($items[$index]['type'] ?? ''))][$slots[$perm[$n]][0]] ?? 3;
                if ($cost >= $bestCost) {
                    continue 2;
                }
            }
            $best = $perm;
            $bestCost = $cost;
        }

        $out = [];
        foreach ($rest as $n => $index) {
            $out[$index] = $slots[($best ?? array_keys($slots))[$n]];
        }

        return $out;
    }

    /**
     * Перестановки в лексикографическом порядке.
     *
     * @param list<int> $values
     * @return \Generator<int, list<int>>
     */
    private static function permutations(array $values): \Generator
    {
        if (count($values) <= 1) {
            yield $values;

            return;
        }
        foreach ($values as $i => $value) {
            $others = $values;
            unset($others[$i]);
            foreach (self::permutations(array_values($others)) as $tail) {
                yield [$value, ...$tail];
            }
        }
    }

    private static function kind(string $type): string
    {
        return match ($type) {
            'photo', 'video' => 'media',
            'quote', 'info' => 'text',
            'stat' => 'stat',
            default => 'deco',
        };
    }

    /**
     * Каскад: три колонки со смещением, как свёрстанная вручную полоса.
     *
     * Элемент уходит в самую короткую колонку — так низ композиции
     * выравнивается сам, без пустой колонки рядом с переполненной. Высоты
     * оценочные (доли ширины колонки): у кадров их задаёт пропорция, у
     * текста — примерный объём, и ошибка в оценке стоит лишь неровного низа.
     * Вторая колонка начинается ниже первой; если есть печать, она и
     * занимает это место — смещение перестаёт быть пустотой.
     *
     * @param array{complete: bool, columns: string, rows: string, tiles: list<array{index: int, area: string, shape: string, full: bool, by_badge: string, ratio: string, column: int}>, badge: array{index: int, area: string, at: string}|null, unused: list<int>} $plan
     * @param list<array<string, mixed>> $items
     * @param list<int> $tiles
     * @return array{complete: bool, columns: string, rows: string, tiles: list<array{index: int, area: string, shape: string, full: bool, by_badge: string, ratio: string, column: int}>, badge: array{index: int, area: string, at: string}|null, unused: list<int>}
     */
    private static function cascade(array $plan, array $items, array $tiles, ?int $badge): array
    {
        // Колонок столько, сколько есть чем заполнить: у двух элементов
        // третья колонка стояла бы пустой посередине.
        $columns = min(3, count($tiles));
        // Смещения — доли ширины колонки на полотне 1400px: печать 136px с
        // промежутком, без неё — ступень 120px, у третьей колонки — 40px.
        $heights = array_slice($columns === 2
            ? [0.0, $badge !== null ? 0.35 : 0.18]
            : [0.0, $badge !== null ? 0.35 : 0.26, 0.09], 0, $columns);
        $media = 0;
        $sizes = [];
        $ratios = [];
        foreach ($tiles as $n => $index) {
            $type = (string) ($items[$index]['type'] ?? '');
            $ratio = '';
            if (in_array($type, self::MEDIA, true)) {
                // Кадры чередуют книжный и квадратный: одинаковые пропорции
                // выстраивали бы колонки в таблицу.
                $ratio = $media % 2 === 0 ? '4-5' : '1-1';
                $media++;
            } elseif ($type === 'pattern') {
                $ratio = '3-1';
            }
            $ratios[$n] = $ratio;
            // Высота текста — по числу строк: около сорока знаков в строке
            // колонки, у справки — по строке на пару «подпись | значение».
            $sizes[$n] = 0.045 + match (true) {
                $ratio === '4-5' => 1.25,
                $ratio === '1-1' => 1.0,
                $ratio === '3-1' => 0.34,
                $type === 'stat' => 0.44,
                $type === 'badge' => 1.0,
                $type === 'info' => 0.3 + 0.085 * count(CollageBlockNormalizer::infoRows((string) ($items[$index]['info_rows'] ?? ''))),
                default => 0.32 + 0.06 * (int) ceil(mb_strlen((string) ($items[$index]['quote_text'] ?? '')) / 38),
            };
        }

        // Колонки подбираются перебором, а не «в самую короткую по ходу»:
        // жадный проход отдавал последний книжный кадр колонке, которая была
        // короткой до него, и разница низов доходила до высоты целой плитки
        // (замерено: 1090 против 590px). Вариантов 3^7 = 2187 — это доли
        // миллисекунды. Первая плитка стоит в первой колонке: композиция
        // начинается с левого верхнего угла, как и читается.
        $best = null;
        $bestSpread = PHP_FLOAT_MAX;
        $count = count($tiles);
        $total = $columns ** max(0, $count - 1);
        for ($code = 0; $code < $total; $code++) {
            $assign = [0];
            $rest = $code;
            for ($n = 1; $n < $count; $n++) {
                $assign[] = $rest % $columns;
                $rest = intdiv($rest, $columns);
            }
            $bottoms = $heights;
            foreach ($assign as $n => $column) {
                $bottoms[$column] += $sizes[$n];
            }
            // Пустая колонка — не смещение, а дыра.
            if ($count >= $columns && count(array_unique($assign)) < $columns) {
                continue;
            }
            // При близком разбросе побеждает порядок чтения: ранний элемент
            // левее позднего. Вес мал — ровный низ важнее.
            $inversions = 0;
            foreach ($assign as $i => $a) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $inversions += $a > $assign[$j] ? 1 : 0;
                }
            }
            $spread = max($bottoms) - min($bottoms) + 0.02 * $inversions;
            if ($spread < $bestSpread - 1e-9) {
                $best = $assign;
                $bestSpread = $spread;
            }
        }
        $best ??= array_fill(0, $count, 0);

        $placed = [];
        foreach ($tiles as $n => $index) {
            $placed[] = ['index' => $index, 'column' => $best[$n], 'ratio' => $ratios[$n]];
        }

        // Первая плитка на телефоне — во всю ширину: на её угол ложится
        // печать, и у широкой плитки этот угол свободен.
        $full = self::narrowFull($items, $tiles, $tiles[0]);
        foreach ($placed as $tile) {
            $plan['tiles'][] = [
                'index' => $tile['index'],
                'area' => '',
                'shape' => '',
                'full' => isset($full[$tile['index']]),
                'by_badge' => '',
                'ratio' => $tile['ratio'],
                'column' => $tile['column'],
            ];
        }
        if ($badge !== null) {
            $plan['badge'] = ['index' => $badge, 'area' => '', 'at' => 'column'];
        }

        return $plan;
    }

    /**
     * Узкий вариант: две колонки, элементы в порядке вывода. Во всю ширину
     * идут ведущий и типы из NARROW_WIDE, а ещё — одиночная плитка перед
     * широкой и в конце списка: иначе рядом с ней оставалась бы дыра.
     *
     * @param list<array<string, mixed>> $items
     * @param list<int> $order
     * @return array<int, true>
     */
    private static function narrowFull(array $items, array $order, int $lead): array
    {
        $full = [];
        $pending = null;
        foreach ($order as $index) {
            $wide = $index === $lead || in_array((string) ($items[$index]['type'] ?? ''), self::NARROW_WIDE, true);
            if ($wide) {
                $full[$index] = true;
                if ($pending !== null) {
                    $full[$pending] = true;
                    $pending = null;
                }
                continue;
            }
            $pending = $pending === null ? $index : null;
        }
        if ($pending !== null) {
            $full[$pending] = true;
        }

        return $full;
    }

    /**
     * Что сказать редактору о составе ансамбля.
     *
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    public static function problems(string $layout, array $items): array
    {
        if (!self::isEnsemble($layout)) {
            return [];
        }

        $label = CollageLayout::LAYOUTS[$layout];
        $plan = self::plan($layout, $items);
        $problems = [];
        if ($items !== [] && !$plan['complete']) {
            $problems[] = $layout === 'panorama'
                ? '«' . $label . '» строится вокруг фотографии или видео, а их в элементах нет — блок не будет показан.'
                : '«' . $label . '» не из чего собрать: кроме печати в элементах ничего нет — блок не будет показан.';
        }
        if ($plan['unused'] !== []) {
            $problems[] = '«' . $label . '» показывает до ' . self::CAPACITY . ' элементов и одну печать: '
                . count($plan['unused']) . ' ' . CollageComposition::plural(count($plan['unused'])) . ' не поместились. Уберите лишние или выберите «Шахматку».';
        }

        return $problems;
    }
}
