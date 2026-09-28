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
     * @var array<string, array<string, array{types: list<string>, take: int, required: bool, ordered?: bool}>>
     */
    public const RECIPES = [
        'callout' => [
            'photo' => ['types' => CollageEnsemble::MEDIA, 'take' => 1, 'required' => true],
            'card' => ['types' => ['stat', 'quote', 'info'], 'take' => 1, 'required' => false],
            'badge' => ['types' => ['badge'], 'take' => 1, 'required' => false],
            'pattern' => ['types' => ['pattern'], 'take' => 1, 'required' => false],
        ],
        // Второй кадр — отдельная роль, а не «две фотографии»: у первого
        // вырез под второй, второй несёт карточку, и поменять их местами
        // значит переставить элементы в списке.
        'pair' => [
            'photo' => ['types' => CollageEnsemble::MEDIA, 'take' => 1, 'required' => true],
            'second' => ['types' => CollageEnsemble::MEDIA, 'take' => 1, 'required' => true],
            'card' => ['types' => ['stat', 'quote', 'info'], 'take' => 1, 'required' => false],
            'badge' => ['types' => ['badge'], 'take' => 1, 'required' => false],
            'pattern' => ['types' => ['pattern'], 'take' => 1, 'required' => false],
        ],
        // Вырез здесь крупнее, чем у «Кадра и выноски», и строится под строки
        // «часы приёма, телефон», поэтому типы карточки идут по предпочтению
        // (`ordered`): справка забирает место, даже если число стоит в списке
        // раньше. У остальных раскладок решает порядок элементов.
        'notch' => [
            'photo' => ['types' => CollageEnsemble::MEDIA, 'take' => 1, 'required' => true],
            'card' => ['types' => ['info', 'stat', 'quote'], 'take' => 1, 'required' => false, 'ordered' => true],
            'badge' => ['types' => ['badge'], 'take' => 1, 'required' => false],
        ],
        'diagonal' => [
            'photo' => ['types' => CollageEnsemble::MEDIA, 'take' => 1, 'required' => true],
            'second' => ['types' => CollageEnsemble::MEDIA, 'take' => 1, 'required' => true],
            'card' => ['types' => ['stat', 'quote', 'info'], 'take' => 1, 'required' => false],
            'badge' => ['types' => ['badge'], 'take' => 1, 'required' => false],
        ],
        'portrait' => [
            'photo' => ['types' => CollageEnsemble::MEDIA, 'take' => 1, 'required' => true],
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
        /** @var array<string, list<int>> $slots */
        $slots = [];
        $used = [];
        foreach ($recipe as $slot => $rule) {
            $slots[$slot] = [];
            $passes = !empty($rule['ordered']) ? array_map(static fn (string $type): array => [$type], $rule['types']) : [$rule['types']];
            foreach ($passes as $types) {
                foreach ($items as $index => $item) {
                    if (count($slots[$slot]) >= $rule['take']) {
                        break 2;
                    }
                    if (!isset($used[$index]) && in_array((string) ($item['type'] ?? ''), $types, true)) {
                        $slots[$slot][] = $index;
                        $used[$index] = true;
                    }
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
     * Хватает ли элементов, чтобы композиция состоялась: вырез без кадра
     * вырезать не из чего, а «внахлёст» из одного снимка — это просто снимок.
     *
     * @param array{slots: array<string, list<int>>, unused: list<int>} $roles
     */
    public static function complete(string $layout, array $roles): bool
    {
        foreach (self::RECIPES[$layout] ?? [] as $slot => $rule) {
            if ($rule['required'] && ($roles['slots'][$slot] ?? []) === []) {
                return false;
            }
        }

        return true;
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
        if (CollageEnsemble::isEnsemble($layout)) {
            return CollageEnsemble::problems($layout, $items);
        }
        if (!CollageLayout::isComposed($layout)) {
            return [];
        }

        $label = CollageLayout::LAYOUTS[$layout];
        $problems = [];
        $roles = self::roles($layout, $items);
        if (!self::complete($layout, $roles)) {
            $problems[] = isset(self::RECIPES[$layout]['second'])
                ? '«' . $label . '» строится на двух кадрах (фотография или видео), а в элементах их меньше — блок не будет показан.'
                : '«' . $label . '» строится вокруг фотографии или видео, а их в элементах нет — блок не будет показан.';
        }
        if ($roles['unused'] !== []) {
            $problems[] = '«' . $label . '» показывает не все элементы: ' . count($roles['unused'])
                . ' ' . self::plural(count($roles['unused'])) . ' не нашли места в композиции. Уберите лишние или выберите «Шахматку» — она показывает все.';
        }

        return $problems;
    }

    public static function plural(int $n): string
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
