<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Локальный форматтер дат для госсайта: жёсткая таймзона Asia/Tashkent и
 * правильные формы на трёх языках без intl:
 *   ru — «9 июля 2026 г.» (родительный падеж),
 *   uz — «9-iyul, 2026-yil»,
 *   en — «July 9, 2026».
 */
final class DateFormatter
{
    public const TIMEZONE = 'Asia/Tashkent';

    private const MONTHS = [
        'ru' => [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
            'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
        'uz' => [1 => 'yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun',
            'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'],
    ];

    /**
     * Длинная дата на языке $lang. Вход — timestamp, «Y-m-d», «Y-m-d H:i:s»
     * или любой формат, понятный strtotime. Непригодный вход → ''.
     */
    public static function long(string|int $date, string $lang = 'ru'): string
    {
        $dt = self::toDateTime($date);
        if ($dt === null) {
            return '';
        }

        $day = (int) $dt->format('j');
        $month = (int) $dt->format('n');
        $year = (int) $dt->format('Y');
        $months = self::MONTHS[$lang] ?? self::MONTHS['ru'];

        return match ($lang) {
            'uz' => sprintf('%d-%s, %d-yil', $day, $months[$month], $year),
            'en' => sprintf('%s %d, %d', $months[$month], $day, $year),
            default => sprintf('%d %s %d г.', $day, $months[$month], $year),
        };
    }

    /** Короткая дата «09.07.2026» в ташкентском времени. */
    public static function short(string|int $date): string
    {
        $dt = self::toDateTime($date);

        return $dt === null ? '' : $dt->format('d.m.Y');
    }

    /** Дата и время «09.07.2026 14:30» в ташкентском времени. */
    public static function dateTime(string|int $date): string
    {
        $dt = self::toDateTime($date);

        return $dt === null ? '' : $dt->format('d.m.Y H:i');
    }

    /**
     * Произвольный формат в ташкентском времени; непригодный вход → ''.
     *
     * Заменяет связку `date($fmt, strtotime($value))`, которой было засеяно
     * пол-проекта. У неё две беды. Первая: `strtotime()` на неразобранной дате
     * возвращает `false`, и в файле со `strict_types` это `TypeError` — одна
     * кривая запись в базе роняла карту сайта и RSS целиком, а во вьюхах
     * молча печаталось 1 января 1970 года. Вторая: `date()` считает в
     * таймзоне сервера, а весь остальной сайт — в ташкентской, поэтому даты
     * рядом на одной странице могли расходиться на часы.
     */
    public static function format(string|int $date, string $format): string
    {
        $dt = self::toDateTime($date);

        return $dt === null ? '' : $dt->format($format);
    }

    private static function toDateTime(string|int $date): ?\DateTimeImmutable
    {
        try {
            $tz = new \DateTimeZone(self::TIMEZONE);
            if (is_int($date)) {
                return (new \DateTimeImmutable('@' . $date))->setTimezone($tz);
            }
            $date = trim($date);
            if ($date === '' || str_starts_with($date, '0000-00-00')) {
                return null;
            }

            return new \DateTimeImmutable($date, $tz);
        } catch (\Throwable) {
            return null;
        }
    }
}
