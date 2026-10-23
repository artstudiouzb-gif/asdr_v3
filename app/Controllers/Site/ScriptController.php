<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\A11ySettings;
use App\Core\UzCyrillic;

/**
 * Переключение письменности узбекского текста: латиница ↔ кириллица.
 * Отдельный контент для кириллицы не хранится — она получается
 * транслитерацией (см. App\Core\UzCyrillic), поэтому выбор живёт рядом с
 * остальными настройками отображения, в одном cookie.
 */
final class ScriptController
{
    /** @param array<string, string> $params */
    public function switch(array $params): void
    {
        // Выключенная кириллица не включается и старой ссылкой из закладок.
        $code = UzCyrillic::enabled() ? (string) ($params['code'] ?? 'latn') : 'latn';
        A11ySettings::rememberScript($code);

        header('Location: ' . self::safeRedirect((string) ($_GET['to'] ?? '/')), true, 302);
        exit;
    }

    /**
     * Возвращаться можно только на свой же адрес: «to» приходит из ссылки,
     * а переход по чужому адресу — открытый редирект.
     */
    public static function safeRedirect(string $to): string
    {
        // Адрес возврата — только путь своего сайта. Пробельные и управляющие
        // символы отклоняются целиком, а не вырезаются: браузер выбрасывает
        // табуляцию и переводы строки из адреса, и путь «/<символ>/хост»
        // превращался бы в ссылку на чужой сайт. Обратная косая черта для
        // браузера — та же прямая.
        if ($to === ''
            || $to[0] !== '/'
            || preg_match('/[\x00-\x20\x7F\\\\]/', $to) === 1
            || str_starts_with($to, '//')) {
            return '/';
        }

        return $to;
    }
}
