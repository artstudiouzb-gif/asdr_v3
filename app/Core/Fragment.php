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
        $html = View::renderPartial($template, $data);
        if (Asset::cdnBase() !== '') {
            $html = Asset::rewriteMedia($html);
        }

        header('Content-Type: text/html; charset=UTF-8');
        // Фрагмент — такой же публичный GET-ответ, как и страница: те же
        // заголовки кэширования (шаблон 'site/' включает их применение).
        PublicResponseCache::apply('site/fragment');
        echo $html;
    }
}
