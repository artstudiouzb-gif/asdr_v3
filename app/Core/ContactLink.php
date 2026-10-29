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
     * Пометка внутреннего номера так, как её пишут на языках сайта:
     * «вн. 123», «доб. 123», «içki raq. 123» (и старой латиницей «ichki»),
     * «ички рақ. 123», «ext. 123».
     */
    private const EXTENSION_MARK = '(?:вн|доб|ext|içki\s*raq(?:am)?|ichki\s*raq(?:am)?|ички\s*рақ(?:ам)?)\.?';

    /**
     * Адрес `tel:` для номера, как его набрал редактор.
     *
     * Внутренний номер набирается после паузы (`,,`): прежде цифры склеивались
     * подряд, и «71-203-03-08 (вн. 123)» превращалось в несуществующий
     * 712030308123. Номер без кода страны (9 цифр) получает +998 — иначе с
     * иностранной SIM звонок уходил бы не туда. Меньше семи цифр — не номер,
     * ответ null.
     */
    public static function telHref(string $phone): ?string
    {
        $phone = trim($phone);
        // Основной номер — до первой буквы, запятой или решётки: дальше
        // начинается пометка внутреннего («вн.», «ext») или он сам («, 123»).
        $parts = preg_split('/[\p{L},#]/u', $phone, 2) ?: [$phone];
        $digits = preg_replace('/\D+/', '', $parts[0]) ?? '';
        if (strlen($digits) < self::MIN_PHONE_DIGITS) {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            $main = '+' . $digits;
        } elseif (strlen($digits) === 9) {
            $main = '+998' . $digits;
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '998')) {
            $main = '+' . $digits;
        } else {
            $main = $digits;
        }

        $ext = '';
        if (isset($parts[1]) && preg_match('/\d{1,6}/', $parts[1], $m) === 1) {
            $ext = ',,' . $m[0];
        }

        return 'tel:' . $main . $ext;
    }

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
            . '|(?P<phone>\+?\d[\d\s\-()]{5,}\d'
            . '(?:\s*\(?\s*' . self::EXTENSION_MARK . '\s*\d{1,6}\)?)?)~iu';

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
                $href = self::telHref($phone);
                if ($href === null) {
                    return $phone;
                }
                // «2020–2026» под шаблон номера подходит, но звонить туда некуда.
                if (preg_match('/^\d{4}\s*[-–]\s*\d{4}$/u', trim($phone)) === 1) {
                    return $phone;
                }

                return '<a class="contact-link contact-link--tel" href="' . $href . '">'
                    . $phone . '</a>';
            },
            $safe
        );

        return $result ?? $safe;
    }
}
