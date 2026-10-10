<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сообщение посетителям при закрытом сайте (`maintenance_message`).
 *
 * Поле правится визуальным редактором: перенос строки, жирность, цвет и
 * размер. Хранится очищенный HTML (HtmlSanitizer::sanitizeNotice), а на
 * выводе чистится ещё раз — значение могло попасть в базу и в обход формы
 * (перенос сайта, ручная правка). Записи, сохранённые до редактора, —
 * обычный текст: тегов в них нет, поэтому они экранируются, а переводы
 * строк становятся <br>.
 */
final class MaintenanceMessage
{
    /** Предел исходной разметки: сообщение короткое, а не страница. */
    public const MAX_LENGTH = 5000;

    /** Приводит присланное из формы к хранимому виду; пустой текст — пустая строка. */
    public static function normalize(string $input): string
    {
        $html = HtmlSanitizer::sanitizeNotice(mb_substr($input, 0, self::MAX_LENGTH));

        return self::isBlank($html) ? '' : $html;
    }

    /** Готовая разметка для страницы-заглушки; пустое значение — запасной текст. */
    public static function html(string $stored, string $fallback): string
    {
        if (self::isBlank($stored)) {
            return '<p>' . htmlspecialchars($fallback, ENT_QUOTES) . '</p>';
        }
        // Разметку узнаём по тегу из разрешённого набора, а не по знаку «<»:
        // в прежнем обычном тексте он мог стоять сам по себе («<до> утра»).
        if (preg_match('~</?(?:p|br|span|strong|b|em|i|u|s|a)\b[^>]*>~i', $stored) !== 1) {
            return '<p>' . nl2br(htmlspecialchars(trim($stored), ENT_QUOTES)) . '</p>';
        }

        return HtmlSanitizer::sanitizeNotice($stored);
    }

    /** Разметка без видимого текста (TinyMCE оставляет «<p>&nbsp;</p>»). */
    private static function isBlank(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }
}
