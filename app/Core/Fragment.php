<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ответ-фрагмент для AJAX-фильтрации списков: тот же контроллер отдаёт только
 * область результатов, без шапки, меню и подвала.
 *
 * Режим включается ПАРАМЕТРОМ URL, а не заголовком: фрагмент и полная страница
 * должны иметь разные адреса, иначе общий кэш (CDN) отдал бы кусок разметки
 * вместо страницы. По той же причине здесь не нужен Vary.
 *
 * Без JS всё работает по-старому: ссылки фильтров и пагинации остаются
 * обычными ссылками на полные страницы.
 */
final class Fragment
{
    public const PARAM = '_fragment';

    public static function wanted(): bool
    {
        return (string) ($_GET[self::PARAM] ?? '') === '1';
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): void
    {
        $html = self::html($template, $data);

        header('Content-Type: text/html; charset=UTF-8');
        // Фрагмент — такой же публичный GET-ответ, как и страница: те же
        // заголовки кэширования (шаблон 'site/' включает их применение).
        PublicResponseCache::apply('site/fragment');
        echo $html;
    }

    /**
     * Разметка фрагмента в том же виде, что и у полной страницы: письменность
     * и адреса CDN. Пагинация и фильтры ленты приходят именно фрагментом, и
     * без кириллицы здесь вторая страница возвращалась латиницей.
     *
     * @param array<string,mixed> $data
     */
    public static function html(string $template, array $data = []): string
    {
        $html = View::renderPartial($template, $data);
        if (View::wantsCyrillic()) {
            $html = UzCyrillic::html($html);
        }
        if (Asset::cdnBase() !== '') {
            $html = Asset::rewriteMedia($html);
        }

        return $html;
    }
}
