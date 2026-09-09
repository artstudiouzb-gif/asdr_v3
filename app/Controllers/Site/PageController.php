<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\BlockRenderer;
use App\Core\ContentLanguageNotice;
use App\Core\Locale;
use App\Core\View;
use App\Models\Block;
use App\Models\Page;

final class PageController
{
    public function home(): void
    {
        $lang = Locale::current();
        $page = Page::findHome($lang);

        if (!$page) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        if (ContentLanguageNotice::renderIfMissing(Page::availableLangs((int) $page['id']), '/')) {
            return;
        }

        $this->renderPage($page, $lang, true);
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $lang = Locale::current();
        $slug = $params['slug'] ?? '';
        $page = Page::findBySlug($slug, $lang);

        if (!$page) {
            // Раздел каталога, вынесенный в корень («Документы» на /documenty,
            // а не /catalog/documenty). Страница проверяется первой: этот
            // маршрут её и обслуживает, а перенос типа в корень запрещён,
            // пока адрес занят страницей (ContentType::rootUrlConflict).
            if ((new ContentController())->tryRootIndex((string) $slug)) {
                return;
            }
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $canonicalSlug = (string) ($page['slug'] ?? '');
        if (($page['lang'] ?? '') === $lang && $canonicalSlug !== '' && $slug !== $canonicalSlug) {
            header('Location: ' . Locale::url($canonicalSlug, $lang), true, 301);
            exit;
        }

        // Главная доступна по «/», а не «/{slug}» — со slug'ом это дубль
        // контента. Постоянный редирект на канонический корневой URL.
        if (!empty($page['is_home'])) {
            header('Location: ' . Locale::url('/'), true, 301);
            exit;
        }

        // Шапка раздела — тот же случай: заголовок, лид и блоки этой страницы
        // выводятся на /news или /projects, и со своим slug'ом получалось два
        // адреса с одинаковым содержимым и canonical на самих себя.
        $pageSection = Page::sectionOf($page, $lang);
        if ($pageSection !== '') {
            header('Location: ' . Page::sectionUrl($pageSection, $lang), true, 301);
            exit;
        }

        if (ContentLanguageNotice::renderIfMissing(Page::availableLangs((int) $page['id']), '/' . $slug)) {
            return;
        }

        $this->renderPage($page, $lang);
    }

    private function renderPage(array $page, string $lang, bool $isHome = false): void
    {
        // Переключатель языков и hreflang показывают только языки, на которых
        // страница реально наполнена (перевод или собственный стек блоков).
        $langs = Page::availableLangs((int) $page['id']);
        Locale::setContentLangs($langs);
        // У главной адрес всегда корневой («/» и «/uz»). Пути из группы
        // переводов дали бы «/uz/home»: адрес рабочий, но отвечает редиректом
        // на корень — и переключатель, и hreflang вели бы через лишний шаг.
        // Признак берём от маршрута: у языковой версии-записи `is_home` своего
        // флага может не быть, а живёт она всё равно в корне своего языка.
        Locale::setAlternatePaths(
            $isHome || !empty($page['is_home'])
                ? array_fill_keys($langs, '/')
                : \App\Core\TranslationGroupHelper::publishedPaths('pages', (int) $page['id'])
        );

        // Сборка блоков (кэш, свежий CSRF, nonce, ассеты) общая со страницей
        // проекта — она живёт в App\Core\PageBlocks.
        $rendered = \App\Core\PageBlocks::compile((int) $page['id'], $lang);

        $layoutType = $page['layout_type'] ?? 'no_sidebar';
        // Виджеты собираются вне кэша блоков: правка виджета видна сразу.
        $sidebar = \App\Core\WidgetRenderer::sidebarFor($layoutType, $lang);

        View::render('site/page', [
            'page' => $page,
            'content' => $rendered['html'],
            'blockCss' => $rendered['css'],
            'preloadImages' => $rendered['preload_images'] ?? [],
            'layoutType' => $layoutType,
            'sidebar' => $sidebar,
        ]);
    }
}
