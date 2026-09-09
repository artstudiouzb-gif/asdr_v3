<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Телефоны и адреса почты в строках контактов делаются кликабельными.
 *
 * Контактные блоки выводили строки простым текстом: на телефоне номер
 * приходилось запоминать и набирать вручную, а почту — выделять и копировать.
 * Здесь строка экранируется целиком, а затем найденные номера и адреса
 * оборачиваются в `tel:`/`mailto:`.
 */
final class ContactLink
{
    /** Минимум цифр, чтобы последовательность считалась номером, а не годом. */
    private const MIN_PHONE_DIGITS = 7;

    /**
     * Мини-иконка для строки контакта — по её содержимому, а не по подписи:
     * «Приёмная: +998 …» получает трубку, «info@…» конверт, «Пн–Пт 9:00–18:00»
     * часы. Строка без узнаваемого содержимого остаётся без значка — лучше
     * пусто, чем случайная картинка.
     */
    public static function iconFor(string $line): ?string
    {
        if (preg_match('~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u', $line) === 1) {
            return 'mail';
        }

        // Часы работы проверяем до телефона: «9:00–18:00» состоит из цифр и
        // под шаблон номера тоже подходит.
        if (preg_match('~\d{1,2}[:.]\d{2}~u', $line) === 1) {
            return 'clock';
        }

        if (preg_match('~\+?\d[\d\s\-()]{5,}\d~u', $line, $m) === 1
            && strlen((string) preg_replace('/\D+/', '', $m[0])) >= self::MIN_PHONE_DIGITS) {
            return 'phone';
        }

        return null;
    }

    /**
     * Готовая к выводу строка: экранированная, с ссылками на номера и почту.
     */
    public static function linkify(string $line): string
    {
        $safe = htmlspecialchars($line, ENT_QUOTES);

        $pattern = '~(?P<email>[\w.+-]+@[\w-]+(?:\.[\w-]+)+)'
            . '|(?P<phone>\+?\d[\d\s\-()]{5,}\d)~u';

        $result = preg_replace_callback(
            $pattern,
            static function (array $m): string {
                if ($m['email'] !== '') {
                    // Символы адреса ограничены [\w.+-]@ — кодировать нечего,
                    // и строка уже экранирована для HTML.
                    return '<a class="contact-link contact-link--mail" href="mailto:'
                        . $m['email'] . '">' . $m['email'] . '</a>';
                }

                $phone = (string) ($m['phone'] ?? '');
                $digits = preg_replace('/\D+/', '', $phone) ?? '';
                if (strlen($digits) < self::MIN_PHONE_DIGITS) {
                    return $phone;
                }
                // «2020–2026» под шаблон номера подходит, но звонить туда некуда.
                if (preg_match('/^\d{4}\s*[-–]\s*\d{4}$/u', trim($phone)) === 1) {
                    return $phone;
                }

                $href = (str_starts_with(trim($phone), '+') ? '+' : '') . $digits;

                return '<a class="contact-link contact-link--tel" href="tel:' . $href . '">'
                    . $phone . '</a>';
            },
            $safe
        );

        return $result ?? $safe;
    }
}
