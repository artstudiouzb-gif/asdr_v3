<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\MenuItem;

/**
 * Меню текущего раздела: пункт основного меню, внутри которого находится
 * открытая страница, и его подпункты.
 *
 * Зачем: в шапке видно только название раздела, а внутри «Об Агентстве»
 * десяток страниц. Без бокового меню читатель не видит ни того, где он
 * находится, ни того, что лежит рядом, — приходится возвращаться в шапку и
 * открывать выпадающий список заново.
 *
 * Своего дерева здесь нет: раздел — это тот же пункт `menu_items`, что и в
 * шапке. Второй список разделов разъехался бы с меню при первой же правке.
 */
final class SectionMenu
{
    /** Путь текущего запроса без завершающего слэша. */
    public static function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return rtrim($path, '/') ?: '/';
    }

    /**
     * Совпадает ли адрес пункта меню с текущей страницей. Совпадением считается
     * и вложенный адрес (`/about/history` внутри `/about`), кроме корня и
     * корней языков: они префикс для всего сайта и подсвечивали бы каждый пункт.
     *
     * Это подсветка, а не «вы здесь»: для `aria-current="page"` нужен точный
     * адрес (`isCurrentPath`), иначе диктор объявит текущей ещё и страницу
     * раздела, на которой посетитель не находится.
     */
    public static function isUrlActive(string $targetUrl, string $currentPath): bool
    {
        $targetPath = parse_url($targetUrl, PHP_URL_PATH) ?: '/';
        $targetPath = rtrim($targetPath, '/') ?: '/';

        if ($currentPath === $targetPath) {
            return true;
        }

        if ($targetPath === '/' || in_array($targetPath, ['/ru', '/uz', '/en', '/kk', '/tr', '/de'], true)) {
            return false;
        }

        return str_starts_with($currentPath, $targetPath . '/');
    }

    /** Точное совпадение адреса с открытой страницей — «вы здесь». */
    public static function isCurrentPath(string $targetUrl, string $currentPath): bool
    {
        $targetPath = parse_url($targetUrl, PHP_URL_PATH) ?: '/';

        return (rtrim($targetPath, '/') ?: '/') === $currentPath;
    }

    /**
     * Ветка меню, в которой находится текущая страница.
     *
     * Пустой ответ (`null`) означает «показывать нечего»: страница не входит ни
     * в один раздел или раздел состоит из одного пункта. Виджет в этом случае
     * не выводится вовсе — пустая рамка с заголовком хуже её отсутствия.
     *
     * @return array{title:string, url:string, active:bool, current:bool, items:list<array{title:string, url:string, active:bool, current:bool}>}|null
     */
    public static function branch(string $lang, string $currentPath): ?array
    {
        foreach (MenuItem::activeForLang($lang) as $item) {
            if (!empty($item['is_divider'])) {
                continue;
            }

            $children = [];
            foreach ((array) ($item['children'] ?? []) as $child) {
                if ((int) ($child['is_active'] ?? 0) !== 1 || !empty($child['is_divider'])) {
                    continue;
                }
                $childUrl = MenuItem::resolveUrl($child, $lang);
                $children[] = [
                    'title' => (string) $child['title'],
                    'url' => $childUrl,
                    'active' => self::isUrlActive($childUrl, $currentPath),
                    'current' => self::isCurrentPath($childUrl, $currentPath),
                ];
            }
            if ($children === []) {
                continue;
            }

            $url = MenuItem::resolveUrl($item, $lang);
            $active = self::isUrlActive($url, $currentPath);
            foreach ($children as $child) {
                if ($child['active']) {
                    $active = true;
                    break;
                }
            }
            if (!$active) {
                continue;
            }

            return [
                'title' => (string) $item['title'],
                'url' => $url,
                'active' => $active,
                // «Вы здесь» — только на собственном адресе раздела: на его
                // внутренней странице заголовок ветки текущей страницей не
                // является.
                'current' => self::isCurrentPath($url, $currentPath),
                'items' => $children,
            ];
        }

        return null;
    }
}
