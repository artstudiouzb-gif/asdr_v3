<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Условия показа блока: расписание (окно дат) и устройство.
 *
 * Расписание считается на сервере, поэтому блок вне окна вообще не попадает в
 * HTML (не индексируется и не «мигает» до отработки JS). Чтобы кэш страницы не
 * заморозил блок после наступления даты, render() собирает ближайшую границу
 * расписания, а PageController пересобирает кэш, когда она прошла.
 *
 * Устройство, наоборот, решается на CSS: кэш страницы общий для всех
 * посетителей, и серверное ветвление по User-Agent сделало бы его непригодным.
 */
final class BlockVisibility
{
    /** @var list<string> */
    public const DEVICES = ['', 'desktop', 'mobile'];

    /**
     * Разбор значения из input[type=datetime-local] ("2026-07-18T14:30") в
     * timestamp в таймзоне приложения. Пусто/мусор -> null.
     */
    public static function parse(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $value));

        return $ts === false ? null : $ts;
    }

    /** Нормализация для хранения: "Y-m-d H:i" либо '' (не задано). */
    public static function normalize(mixed $value): string
    {
        $ts = self::parse($value);

        return $ts === null ? '' : date('Y-m-d H:i', $ts);
    }

    /** Значение для input[type=datetime-local]. */
    public static function forInput(mixed $value): string
    {
        $ts = self::parse($value);

        return $ts === null ? '' : date('Y-m-d\TH:i', $ts);
    }

    /**
     * Виден ли блок сейчас с точки зрения расписания. Границы включительные по
     * началу и исключительные по концу: блок «до 18:00» исчезает ровно в 18:00.
     *
     * @param array<string,mixed> $data
     */
    public static function isVisible(array $data, ?int $now = null): bool
    {
        $now = $now ?? time();
        $from = self::parse($data['_visible_from'] ?? '');
        $to = self::parse($data['_visible_to'] ?? '');

        if ($from !== null && $now < $from) {
            return false;
        }
        if ($to !== null && $now >= $to) {
            return false;
        }

        return true;
    }

    /**
     * Ближайший момент в будущем, когда видимость блока изменится (начало или
     * конец окна). null — расписание не задано либо целиком в прошлом.
     *
     * @param array<string,mixed> $data
     */
    public static function boundary(array $data, ?int $now = null): ?int
    {
        $now = $now ?? time();
        $next = null;
        foreach ([$data['_visible_from'] ?? '', $data['_visible_to'] ?? ''] as $raw) {
            $ts = self::parse($raw);
            if ($ts !== null && $ts > $now && ($next === null || $ts < $next)) {
                $next = $ts;
            }
        }

        return $next;
    }

    /**
     * CSS-класс ограничения по устройству ('' — показывать везде).
     *
     * @param array<string,mixed> $data
     */
    public static function deviceClass(array $data): string
    {
        $device = (string) ($data['_visible_device'] ?? '');

        return in_array($device, ['desktop', 'mobile'], true)
            ? ' cms-block--only-' . $device
            : '';
    }

    /**
     * Задано ли у блока хоть какое-то условие показа (для отметки в админке).
     *
     * @param array<string,mixed> $data
     */
    public static function hasConditions(array $data): bool
    {
        return self::parse($data['_visible_from'] ?? '') !== null
            || self::parse($data['_visible_to'] ?? '') !== null
            || in_array((string) ($data['_visible_device'] ?? ''), ['desktop', 'mobile'], true);
    }

    /**
     * Короткое описание условий для списка блоков в админке ('' — условий нет).
     *
     * @param array<string,mixed> $data
     */
    public static function label(array $data, ?int $now = null): string
    {
        $now = $now ?? time();
        $from = self::parse($data['_visible_from'] ?? '');
        $to = self::parse($data['_visible_to'] ?? '');
        $parts = [];

        if ($from !== null && $to !== null) {
            $parts[] = date('d.m.Y H:i', $from) . ' — ' . date('d.m.Y H:i', $to);
        } elseif ($from !== null) {
            $parts[] = 'с ' . date('d.m.Y H:i', $from);
        } elseif ($to !== null) {
            $parts[] = 'до ' . date('d.m.Y H:i', $to);
        }

        if (($from !== null || $to !== null) && !self::isVisible($data, $now)) {
            $parts[] = $from !== null && $now < $from ? 'ещё не показывается' : 'показ завершён';
        }

        $device = (string) ($data['_visible_device'] ?? '');
        if ($device === 'desktop') {
            $parts[] = 'только десктоп';
        } elseif ($device === 'mobile') {
            $parts[] = 'только мобильные';
        }

        return implode(', ', $parts);
    }
}
