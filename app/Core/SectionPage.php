<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Page;

/**
 * Шапка раздела списка (`/news`, `/projects`) из обычной страницы.
 *
 * Заголовок, лид и SEO этих разделов жили в шаблонах: редактор их не менял, а
 * переводились они только словарём. Теперь любую страницу можно назначить
 * шапкой раздела — она отдаёт заголовок, лид и метатеги, а её блоки выводятся
 * под списком. Именно под: шапка раздела и так наверху, а блоки сверху
 * отодвигали бы вниз то, за чем посетитель пришёл.
 *
 * Раздела может не быть вовсе — тогда всё остаётся как было, на строках из
 * шаблона.
 */
final class SectionPage
{
    /**
     * @return array{
     *     title: string,
     *     lead: string,
     *     meta_title: string,
     *     meta_description: string,
     *     content: string,
     *     css: string,
     *     id: int
     * }|null
     */
    public static function render(string $section, ?string $lang = null): ?array
    {
        $page = Page::forSection($section, $lang);
        if ($page === null) {
            return null;
        }

        $id = (int) $page['id'];
        // Блоки собираются тем же конвейером, что и у страницы: общий кэш,
        // свежий CSRF, nonce и регистрация ассетов блоков.
        $rendered = PageBlocks::compile($id, $lang ?? Locale::current());

        return [
            'id' => $id,
            'title' => trim((string) ($page['title'] ?? '')),
            'lead' => trim((string) ($page['lead'] ?? '')),
            'meta_title' => trim((string) ($page['meta_title'] ?? '')),
            'meta_description' => trim((string) ($page['meta_description'] ?? '')),
            'content' => (string) ($rendered['html'] ?? ''),
            'css' => (string) ($rendered['css'] ?? ''),
        ];
    }
}
