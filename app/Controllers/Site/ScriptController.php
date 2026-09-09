<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\A11ySettings;

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
        A11ySettings::rememberScript((string) ($params['code'] ?? 'latn'));

        header('Location: ' . self::safeRedirect((string) ($_GET['to'] ?? '/')), true, 302);
        exit;
    }

    /**
     * Возвращаться можно только на свой же адрес: «to» приходит из ссылки,
     * а переход по чужому адресу — открытый редирект.
     */
    public static function safeRedirect(string $to): string
    {
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_starts_with($to, '/\\')) {
            return '/';
        }

        // Перевод строки в заголовке Location разрывает ответ: отбрасываем всё
        // с первого переноса, а не вырезаем сами символы — иначе «хвост»
        // подклеился бы к адресу.
        $break = strcspn($to, "\r\n");

        return substr($to, 0, $break);
    }
}
