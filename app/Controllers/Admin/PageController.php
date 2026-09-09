<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\AdminListQuery;
use App\Core\Cache;
use App\Core\ConcurrencyException;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Slug;
use App\Core\View;
use App\Models\Block;
use App\Models\Language;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Models\ContentRevision;

final class PageController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = AdminListQuery::normalize(
            $_GET,
            ['newest', 'oldest', 'title_asc', 'title_desc'],
            'newest'
        );
        $total = Page::adminCount($filters);
        [$filters, $pages] = AdminListQuery::fitPage($filters, $total);
        View::render('admin/pages/index', [
            'items' => Page::adminList($filters),
            'filters' => $filters,
            'filterParams' => AdminListQuery::urlParams($filters),
            'total' => $total,
            'pages' => $pages,
            'langCounts' => Page::langCounts(),
        ]);
    }

    /** @param array<string, string> $params */
    public function duplicate(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $newId = Page::duplicate((int) $params['id']);
        if ($newId === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        \App\Core\Cache::forgetPrefix('page:');
        Flash::success('Страница дублирована как черновик.');
        header('Location: /admin/pages/' . $newId . '/edit');
        exit;
    }

    public function create(): void
    {
        Auth::requireLogin();
        View::render('admin/pages/form', [
            'page' => null,
            'translations' => [],
            'parentOptions' => Page::parentOptions(),
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput(null);

        if ($error !== null) {
            View::render('admin/pages/form', [
                'page' => $data,
                'translations' => [],
                'parentOptions' => Page::parentOptions(),
                'error' => $error,
            ]);
            return;
        }

        try {
            $id = Database::transaction(function (\PDO $_pdo) use ($data): int {
                $id = Page::create($data);
                $this->saveTranslations($id);

                return $id;
            });
        } catch (\DomainException $e) {
            View::render('admin/pages/form', [
                'page' => $data,
                'translations' => [],
                'parentOptions' => Page::parentOptions(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        Flash::success('Страница создана. Теперь добавьте блоки контента.');
        header('Location: /admin/pages/' . $id . '/edit?draft_saved=page%3Anew');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $page = Page::findById((int) $params['id']);
        // Проект — запись той же таблицы, но у него свой раздел и своя форма:
        // открывать его как страницу нельзя, иначе редактор увидит чужие поля.
        if (!$page || (string) ($page['entity_type'] ?? 'page') !== 'page') {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $blockLang = $this->resolveBlockLang((string) ($page['lang'] ?? Language::defaultCode()));
        $blocks = Block::forPage((int) $page['id'], $blockLang);
        $usingFallback = false;
        $defaultLang = Language::defaultCode();

        if (empty($blocks) && $blockLang !== $defaultLang) {
            $blocks = Block::forPage((int) $page['id'], $defaultLang);
            $usingFallback = !empty($blocks);
        }

        View::render('admin/pages/form', [
            'page' => $page,
            'translations' => PageTranslation::forPage((int) $page['id']),
            'blocks' => $blocks,
            'blockLang' => $blockLang,
            'usingFallback' => $usingFallback,
            'parentOptions' => Page::parentOptions((int) $page['id']),
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function copyLanguageBlocks(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $fromLang = (string) ($_POST['from_lang'] ?? Language::defaultCode());
        $toLang = (string) ($_POST['to_lang'] ?? $this->resolveBlockLang());
        if (!Page::findById($id)) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        if (!Language::isActive($fromLang) || !Language::isActive($toLang)) {
            Flash::error('Выбран некорректный язык блоков.');
            header('Location: /admin/pages/' . $id . '/edit');
            exit;
        }
        if ($fromLang === $toLang) {
            Flash::error('Исходный и целевой языки должны отличаться.');
            header('Location: /admin/pages/' . $id . '/edit?block_lang=' . urlencode($toLang));
            exit;
        }

        $count = Block::copyLanguageBlocks($id, $fromLang, $toLang);
        Cache::clearPageCache($id);
        Flash::success("Скопировано {$count} блоков с языка " . strtoupper($fromLang) . " на язык " . strtoupper($toLang) . ". Теперь вы можете отредактировать тексты.");

        header('Location: /admin/pages/' . $id . '/edit?block_lang=' . urlencode($toLang));
        exit;
    }

    /** @param array<string, string> $params */
    public function createTranslation(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $id = (int) $params['id'];
        $targetLang = trim((string) ($_POST['target_lang'] ?? ''));
        if ($targetLang === '' || !Language::isActive($targetLang)) {
            Flash::error('Указан некорректный язык перевода.');
            header('Location: /admin/pages/' . $id . '/edit');
            exit;
        }

        try {
            $newId = \App\Core\TranslationGroupHelper::createTranslation('pages', $id, $targetLang);
            Flash::success("Создан отдельный пост перевода страницы на язык «{$targetLang}».");
            header('Location: /admin/pages/' . $newId . '/edit');
            exit;
        } catch (\Throwable $e) {
            Flash::error('Не удалось создать перевод: ' . $e->getMessage());
            header('Location: /admin/pages/' . $id . '/edit');
            exit;
        }
    }

    /**
     * Предпросмотр страницы до публикации (группа 5.2). Рендерит страницу
     * (в т.ч. черновик) со всеми блоками и scoped CSS, но: доступ только
     * авторизованным, noindex, без записи в кэш и sitemap.
     * @param array<string, string> $params
     */
    public function preview(array $params): void
    {
        Auth::requireLogin();

        $page = Page::findById((int) $params['id']);
        if (!$page) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $lang = $this->resolveBlockLang((string) ($page['lang'] ?? Language::defaultCode()));
        $page = Page::localize($page, $lang);

        // Рендерим блоки заново, минуя дисковый кэш (показываем актуальный черновик).
        $blocks = Block::forPageLocalized((int) $page['id'], $lang);
        // В предпросмотре незаполненные блоки показываются заметкой, а не
        // исчезают: иначе редактор не понимает, куда делся добавленный блок.
        \App\Core\BlockRenderer::setPreviewMode(true);
        $rendered = \App\Core\BlockRenderer::renderPage($blocks);
        \App\Core\BlockRenderer::setPreviewMode(false);
        foreach ($rendered['assets'] ?? [] as $assetType) {
            \App\Core\AssetCollector::requireJs($assetType);
        }

        $layoutType = $page['layout_type'] ?? 'no_sidebar';
        $sidebar = \App\Core\WidgetRenderer::sidebarFor($layoutType, $lang);

        View::render('site/page', [
            'page' => $page,
            'content' => $rendered['html'],
            'blockCss' => $rendered['css'],
            'preloadImages' => $rendered['preload_images'] ?? [],
            'layoutType' => $layoutType,
            'sidebar' => $sidebar,
            'robotsNoindex' => true,
            'previewNotice' => true,
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $page = Page::findById($id);
        if (!$page || (string) ($page['entity_type'] ?? 'page') !== 'page') {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        if (!ContentRevision::isFresh('page', $id, (string) ($_POST['expected_updated_at'] ?? ''))) {
            $blockLang = $this->resolveBlockLang();
            View::render('admin/pages/form', [
                'page' => $page,
                'translations' => PageTranslation::forPage($id),
                'error' => 'Страница уже была изменена в другой вкладке или другим пользователем. Текущие данные перезагружены; восстановите локальный черновик и проверьте изменения.',
                'blocks' => Block::forPage($id, $blockLang),
                'blockLang' => $blockLang,
                'parentOptions' => Page::parentOptions($id),
            ]);
            return;
        }

        [$data, $error] = $this->collectInput($id, $page);

        if ($error !== null) {
            $blockLang = $this->resolveBlockLang();
            View::render('admin/pages/form', [
                'page' => array_merge($page, $data),
                'translations' => PageTranslation::forPage($id),
                'error' => $error,
                'blocks' => Block::forPage($id, $blockLang),
                'blockLang' => $blockLang,
                'parentOptions' => Page::parentOptions($id),
            ]);
            return;
        }

        $expectedVersion = (int) ($_POST['expected_lock_version'] ?? 0);
        try {
            Database::transaction(function (\PDO $_pdo) use ($id, $data, $expectedVersion): void {
                ContentRevision::capture('page', $id, Auth::id());
                Page::update($id, $data, $expectedVersion);
                $this->saveTranslations($id);
            });
        } catch (ConcurrencyException) {
            $page = Page::findById($id) ?? $page;
            $blockLang = $this->resolveBlockLang();
            View::render('admin/pages/form', [
                'page' => $page,
                'translations' => PageTranslation::forPage($id),
                'error' => 'Страница уже была изменена в другой вкладке или другим пользователем. Текущие данные перезагружены; восстановите локальный черновик и проверьте изменения.',
                'blocks' => Block::forPage($id, $blockLang),
                'blockLang' => $blockLang,
                'parentOptions' => Page::parentOptions($id),
            ]);
            return;
        } catch (\DomainException $e) {
            $page = Page::findById($id) ?? $page;
            $blockLang = $this->resolveBlockLang();
            View::render('admin/pages/form', [
                'page' => array_merge($page, $data),
                'translations' => PageTranslation::forPage($id),
                'error' => $e->getMessage(),
                'blocks' => Block::forPage($id, $blockLang),
                'blockLang' => $blockLang,
                'parentOptions' => Page::parentOptions($id),
            ]);
            return;
        }
        \App\Core\Cache::clearPageCache($id);

        Flash::success('Страница обновлена.');
        $blockLang = $this->resolveBlockLang();
        header('Location: /admin/pages/' . $id . '/edit?block_lang=' . urlencode($blockLang) . '&draft_saved=page%3A' . $id);
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        Page::delete((int) $params['id']);
        \App\Core\Cache::clearPageCache((int) $params['id']);
        Flash::success('Страница удалена.');
        header('Location: ' . AdminListQuery::returnPath('/admin/pages', $_POST['return_query'] ?? ''));
        exit;
    }

    /** @param array<string, string> $params */
    public function makeHome(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $page = Page::findById($id);
        if (!$page) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        Database::transaction(static function (\PDO $pdo) use ($id): void {
            $pdo->exec('UPDATE pages SET is_home = 0');
            $pdo->exec("UPDATE pages SET is_home = 1, status = 'published', parent_id = NULL WHERE id = " . (int) $id);
        });

        Cache::forgetPrefix('page:');
        Flash::success('Страница «' . htmlspecialchars((string) $page['title'], ENT_QUOTES) . '» назначена Главной страницей сайта.');

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '/admin/pages');
        $returnPath = str_starts_with($referer, '/') && !str_starts_with($referer, '//')
            ? $referer
            : '/admin/pages';
        header('Location: ' . $returnPath);
        exit;
    }

    private function resolveBlockLang(?string $fallback = null): string
    {
        $lang = (string) (
            $_GET['block_lang']
            ?? $_POST['block_lang']
            ?? $_GET['lang']
            ?? $_POST['active_lang_tab']
            ?? $fallback
            ?? Language::defaultCode()
        );

        return Language::isActive($lang) ? $lang : Language::defaultCode();
    }

    private function saveTranslations(int $pageId): void
    {
        $defaultCode = Language::defaultCode();
        $input = (array) ($_POST['translations'] ?? []);

        foreach (Language::active() as $lang) {
            $code = (string) $lang['code'];
            if ($code === $defaultCode || !isset($input[$code])) {
                continue;
            }
            $t = (array) $input[$code];
            PageTranslation::upsert($pageId, $code, [
                'title' => trim((string) ($t['title'] ?? '')),
                'meta_title' => trim((string) ($t['meta_title'] ?? '')),
                'meta_description' => trim((string) ($t['meta_description'] ?? '')),
                'lead' => trim((string) ($t['lead'] ?? '')),
            ]);
        }
    }

    /**
     * Колонки pages.custom_css/custom_js — TEXT (64 КБ). Режем по границе
     * символа: MySQL в строгом режиме отклонил бы весь UPDATE, а в нестрогом
     * молча обрезал бы значение посреди UTF-8.
     */
    private static function limitAssetField(string $value): string
    {
        return trim(mb_strcut(trim($value), 0, \App\Core\CustomAssetHelper::MAX_FIELD_BYTES, 'UTF-8'));
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    private function collectInput(?int $id, ?array $existing = null): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
        $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
        $lead = trim((string) ($_POST['lead'] ?? ''));
        $status = (isset($_POST['publish_action']) || ($_POST['status'] ?? 'draft') === 'published') ? 'published' : 'draft';
        $isHome = !empty($_POST['is_home']);
        $parentRaw = trim((string) ($_POST['parent_id'] ?? ''));
        $parentId = $parentRaw !== '' && $parentRaw !== '0' ? (int) $parentRaw : null;

        // Главная страница сайта ни при каких обстоятельствах не должна слетать в черновик или терять статус is_home при сохранении формы.
        if ($existing !== null && (!empty($existing['is_home']) || (string) ($existing['slug'] ?? '') === 'home' || $slugInput === 'home')) {
            $isHome = true;
            $status = 'published';
        }
        if ($isHome) {
            $parentId = null;
        }
        $layoutType = in_array(
            $_POST['layout_type'] ?? 'no_sidebar',
            ['no_sidebar', 'left_sidebar', 'right_sidebar'],
            true
        ) ? (string) $_POST['layout_type'] : 'no_sidebar';

        if ($title === '') {
            return [[
                'title' => $title,
                'slug' => $slugInput,
                'status' => $status,
                'parent_id' => $parentId,
            ], 'Укажите заголовок страницы.'];
        }

        if ($parentRaw !== '' && $parentRaw !== '0' && (!ctype_digit($parentRaw) || $parentId === null || $parentId <= 0)) {
            return [[
                'title' => $title,
                'slug' => $slugInput,
                'status' => $status,
                'parent_id' => null,
            ], 'Выбрана некорректная родительская страница.'];
        }

        $parentError = Page::validateParent($parentId, $id);
        if ($parentError !== null) {
            return [[
                'title' => $title,
                'slug' => $slugInput,
                'status' => $status,
                'parent_id' => $parentId,
            ], $parentError];
        }

        $lang = (string) ($_POST['lang'] ?? $_GET['lang'] ?? Language::defaultCode());
        if (!Language::isActive($lang)) {
            $lang = Language::defaultCode();
        }

        $rawSlug = $slugInput !== '' ? $slugInput : $title;
        $slug = Slug::unique($rawSlug, static fn (string $s, ?int $ex) => Page::slugExists($s, $ex, $lang), $id);

        // Произвольные CSS/JS — та же поверхность доверия, что и глобальный код
        // в настройках и блок «HTML»: правит только супер-администратор.
        // Редактору сохраняем прежние значения, что бы ни пришло в форме.
        if (\App\Core\Auth::isSuperAdmin()) {
            $customCss = self::limitAssetField((string) ($_POST['custom_css'] ?? ''));
            $customJs = self::limitAssetField((string) ($_POST['custom_js'] ?? ''));
        } else {
            $customCss = trim((string) ($existing['custom_css'] ?? ''));
            $customJs = trim((string) ($existing['custom_js'] ?? ''));
        }

        // Шапка раздела: одна страница на раздел и язык — иначе на сайте
        // выигрывала бы случайная. Занятое место освобождаем молча, это
        // осознанное действие редактора.
        $section = (string) ($_POST['section'] ?? '');
        if (!isset(Page::SECTIONS[$section])) {
            $section = '';
        }

        $data = [
            'title' => $title,
            'slug' => $slug,
            'section' => $section,
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'lead' => $lead !== '' ? $lead : null,
            'status' => $status,
            'is_home' => $isHome,
            'layout_type' => $layoutType,
            'hide_chrome' => !empty($_POST['hide_chrome']), // лендинг (группа 6)
            'transparent_header' => !empty($_POST['transparent_header']),
            'custom_css' => $customCss !== '' ? $customCss : null,
            'custom_js' => $customJs !== '' ? $customJs : null,
            'lang' => $lang,
            'parent_id' => $parentId,
        ];

        return [$data, null];
    }
}
