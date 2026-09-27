<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Роли элементов в композиционных раскладках «Коллажа»
 * (CollageLayout::COMPOSED).
 *
 * Роль выводится из типа элемента и его места в списке, а не задаётся
 * отдельным полем: первая фотография — кадр, первый показатель или цитата —
 * карточка в вырезе и так далее. Отдельное поле «роль» рядом с «типом»
 * повторяло бы его и расходилось бы с ним: показатель, помеченный «кадром»,
 * рисовать нечем.
 *
 * Элемент, которому в композиции нет места, не выводится, и об этом говорит
 * подсказка формы (BlockHints) — молча пропавший элемент хуже любой раскладки.
 */
final class CollageComposition
{
    /**
     * Какие элементы нужны раскладке и сколько каждого берётся. Из этого же
     * списка собирается подсказка в форме.
     *
     * @var array<string, array<string, array{types: list<string>, take: int, required: bool}>>
     */
    public const RECIPES = [
        'callout' => [
            'photo' => ['types' => ['photo'], 'take' => 1, 'required' => true],
            'card' => ['types' => ['stat', 'quote'], 'take' => 1, 'required' => false],
            'badge' => ['types' => ['badge'], 'take' => 1, 'required' => false],
            'pattern' => ['types' => ['pattern'], 'take' => 1, 'required' => false],
        ],
        'portrait' => [
            'photo' => ['types' => ['photo'], 'take' => 1, 'required' => true],
            'stats' => ['types' => ['stat'], 'take' => 2, 'required' => false],
            'quote' => ['types' => ['quote'], 'take' => 1, 'required' => false],
            'badge' => ['types' => ['badge'], 'take' => 1, 'required' => false],
            'pattern' => ['types' => ['pattern'], 'take' => 1, 'required' => false],
        ],
    ];

    /** Шахматке печать на стыке нужна только там, где стык есть: от четырёх клеток. */
    public const CHECKER_JUNCTION_FROM = 4;

    /**
     * @param list<array<string, mixed>> $items
     * @return array{slots: array<string, list<int>>, unused: list<int>}
     */
    public static function roles(string $layout, array $items): array
    {
        if ($layout === 'checker') {
            return self::checker($items);
        }

        $recipe = self::RECIPES[$layout] ?? [];
        $slots = array_fill_keys(array_keys($recipe), []);
        $used = [];
        foreach ($recipe as $slot => $rule) {
            foreach ($items as $index => $item) {
                if (count($slots[$slot]) >= $rule['take']) {
                    break;
                }
                if (!isset($used[$index]) && in_array((string) ($item['type'] ?? ''), $rule['types'], true)) {
                    $slots[$slot][] = $index;
                    $used[$index] = true;
                }
            }
        }

        $unused = [];
        foreach (array_keys($items) as $index) {
            if (!isset($used[$index])) {
                $unused[] = $index;
            }
        }

        return ['slots' => $slots, 'unused' => $unused];
    }

    /**
     * Шахматка берёт все элементы клетками. Печать уходит на стык четырёх
     * клеток, если он есть; иначе она — обычная клетка, а не потерянный
     * элемент.
     *
     * @param list<array<string, mixed>> $items
     * @return array{slots: array<string, list<int>>, unused: list<int>}
     */
    private static function checker(array $items): array
    {
        $badge = null;
        $tiles = [];
        foreach ($items as $index => $item) {
            if ($badge === null && ($item['type'] ?? '') === 'badge') {
                $badge = $index;
                continue;
            }
            $tiles[] = $index;
        }
        if ($badge !== null && count($tiles) < self::CHECKER_JUNCTION_FROM) {
            $tiles[] = $badge;
            sort($tiles);
            $badge = null;
        }

        return ['slots' => ['tiles' => $tiles, 'badge' => $badge === null ? [] : [$badge]], 'unused' => []];
    }

    /**
     * Что сказать редактору о составе композиции: чего не хватает и что не
     * будет показано.
     *
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    public static function problems(string $layout, array $items): array
    {
        if (!CollageLayout::isComposed($layout)) {
            return [];
        }

        $label = CollageLayout::LAYOUTS[$layout];
        $problems = [];
        $roles = self::roles($layout, $items);
        foreach (self::RECIPES[$layout] ?? [] as $slot => $rule) {
            if ($rule['required'] && $roles['slots'][$slot] === []) {
                $problems[] = '«' . $label . '» строится вокруг фотографии, а её в элементах нет — блок не будет показан.';
            }
        }
        if ($roles['unused'] !== []) {
            $problems[] = '«' . $label . '» показывает не все элементы: ' . count($roles['unused'])
                . ' ' . self::plural(count($roles['unused'])) . ' не нашли места в композиции. Уберите лишние или выберите «Шахматку» — она показывает все.';
        }

        return $problems;
    }

    private static function plural(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return 'элемент';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'элемента';
        }

        return 'элементов';
    }
}
