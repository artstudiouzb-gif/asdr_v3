<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Page;

/**
 * Куда возвращаться после работы с блоком — в форму его владельца.
 *
 * Блок принадлежит строке `pages` и про подтип записи не знает: конструктор у
 * страницы и у проекта один и тот же. А вот формы у них разные, и форма
 * страницы на проект отвечает 404 (так разведены разделы админки). Поэтому
 * ссылка «назад к странице», переключатель языка блоков и редиректы после
 * копирования блоков или применения шаблона обязаны спрашивать раздел, а не
 * писать `/admin/pages/` литералом: по такому литералу редактор проекта
 * попадал на 404 ровно в тот момент, когда закончил править блок.
 *
 * Сами POST-адреса конструктора (`/admin/pages/{id}/blocks/add` и соседние)
 * остаются общими: они работают с блоками по id записи, и подтип им безразличен.
 */
final class BlockOwner
{
    /**
     * Адрес записи в её разделе админки, без завершающего слэша.
     *
     * @param array<string, mixed> $page строка `pages`
     */
    public static function baseFor(array $page): string
    {
        return '/admin/' . (self::isProject($page) ? 'projects' : 'pages') . '/' . (int) ($page['id'] ?? 0);
    }

    /**
     * Подтип записи. Отдельным методом, потому что читателей двое: адрес
     * возврата и подпись кнопки — «назад к проекту» вместо «назад к странице».
     * Второе сравнение с `entity_type` по месту разъехалось бы с первым.
     *
     * @param array<string, mixed> $page
     */
    public static function isProject(array $page): bool
    {
        return (string) ($page['entity_type'] ?? 'page') === 'project';
    }

    /**
     * Адрес формы владельца; язык блоков необязателен.
     *
     * @param array<string, mixed> $page строка `pages`
     */
    public static function editUrlFor(array $page, string $lang = ''): string
    {
        return self::withLang(self::baseFor($page), $lang);
    }

    /**
     * То же по одному id — для мест, где строки под рукой нет (форма блока
     * знает только `page_id`). Неизвестная запись остаётся в разделе страниц:
     * прежнее поведение, и 404 там честнее выдумывания раздела.
     */
    public static function editUrl(int $pageId, string $lang = ''): string
    {
        $page = Page::findById($pageId) ?? [];
        $page['id'] = $pageId;

        return self::withLang(self::baseFor($page), $lang);
    }

    private static function withLang(string $base, string $lang): string
    {
        return $base . '/edit' . ($lang !== '' ? '?block_lang=' . urlencode($lang) : '');
    }
}
