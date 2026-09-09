<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Демо-наполнение сайта государственного агентства: главная, проекты, медиа,
 * новости, документы, вакансии, тендеры, руководство, формы и типовые страницы.
 * Идемпотентно — записи создаются только если их ещё нет (по slug/url), а уже
 * настроенные страницы и меню не изменяются.
 */
final class DemoSeeder
{
    private const DEMO_VERSION = '2026.09-v1';

    /** @return array<string,int> счётчики добавленного по разделам */
    /**
     * @param list<string>|null $modules Список модулей для загрузки (null / ['all'] = все)
     * @return array<string,int> счётчики добавленного по разделам
     */
    public static function run(PDO $pdo, ?array $modules = null): array
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $all = $modules === null || in_array('all', $modules, true) || $modules === [];
        $selected = static fn (string $mod): bool => $all || in_array($mod, $modules, true);

        try {
            $c = [
                'assets' => 0,
                'home' => 0,
                'news' => 0,
                'documenty' => 0,
                'vakansii' => 0,
                'tendery' => 0,
                'meropriyatiya' => 0,
                'projects' => 0,
                'albums' => 0,
                'videos' => 0,
                'forms' => 0,
                'team' => 0,
                'pages' => 0,
                'menu' => 0,
                'news_categories' => 0,
                'heroes' => 0,
            ];

            self::seedAssets($pdo, $c);
            if ($selected('news')) {
                self::seedNews($pdo, $c);
            }
            if ($selected('entries') || $selected('documents') || $selected('vacancies') || $selected('tenders') || $selected('events')) {
                self::seedEntries($pdo, $c);
            }
            if ($selected('projects')) {
                self::seedProjects($pdo, $c);
            }
            if ($selected('media') || $selected('albums') || $selected('videos')) {
                self::seedMedia($pdo, $c);
            }
            if ($selected('forms')) {
                self::seedForms($pdo, $c);
            }
            if ($selected('team')) {
                self::seedTeam($pdo, $c);
            }
            if ($selected('home')) {
                self::seedHome($pdo, $c);
            }
            if ($selected('heroes') || $selected('home')) {
                self::seedHeroes($pdo, $c);
            }
            if ($selected('pages')) {
                self::seedPages($pdo, $c);
            }
            if ($selected('menu')) {
                self::seedMenu($pdo, $c);
            }
            self::storeVersion($pdo);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $c;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Выполняет полный сброс разделов (очистку контента) и загрузку эталонного комплекта «с чистого листа».
     *
     * @param list<string>|null $modules
     * @return array<string,int>
     */
    public static function resetAndRun(PDO $pdo, ?array $modules = null): array
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        // Дочерние таблицы идут раньше родительских. Это позволяет выполнить
        // сброс без отключения FOREIGN_KEY_CHECKS и не скрывать реальные ошибки.
        $tables = [
            'hero_slide_translations',
            'hero_slides',
            'heroes',
            'news_poll_votes',
            'news_polls',
            'news_images',
            'news_translations',
            'social_posts',
            'news_views',
            'news',
            'block_revisions',
            'blocks',
            'page_translations',
            'pages',
            'content_entry_translations',
            'content_entries',
            'photo_album_images',
            'photo_album_translations',
            'photo_albums',
            'video_translations',
            'videos',
            'form_submissions',
            'forms',
            'team_member_translations',
            'team_members',
            'menu_items',
            'content_revisions',
        ];

        try {
            foreach ($tables as $t) {
                if (self::tableExists($pdo, $t)) {
                    $pdo->exec('DELETE FROM `' . $t . '`');
                }
            }

            // Медиабиблиотеку пользователя не очищаем целиком: удаляем только
            // записи файлов, которыми управляет этот демо-комплект.
            if (self::tableExists($pdo, 'files')) {
                $assetDir = \dirname(__DIR__, 2) . '/database/demo_assets';
                $demoNames = array_map('basename', glob($assetDir . '/*.jpg') ?: []);
                if ($demoNames !== []) {
                    $placeholders = implode(',', array_fill(0, count($demoNames), '?'));
                    $deleteDemoFiles = $pdo->prepare(
                        "DELETE FROM files WHERE stored_name IN ({$placeholders})"
                    );
                    $deleteDemoFiles->execute($demoNames);
                }
            }

            $result = self::run($pdo, $modules);
            $issues = self::verify($pdo, $modules);
            if ($issues !== []) {
                throw new \RuntimeException(
                    'Проверка демо-данных не пройдена: ' . implode('; ', $issues)
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Проверяет полноту и внутреннюю согласованность эталонного демо-комплекта.
     *
     * @param list<string>|null $modules
     * @return list<string>
     */
    public static function verify(PDO $pdo, ?array $modules = null): array
    {
        $issues = [];
        $all = $modules === null || in_array('all', $modules, true) || $modules === [];
        $selected = static fn (string $mod): bool => $all || in_array($mod, $modules, true);

        $minimums = [
            'news' => 7,
            'news_translations' => 6,
            'news_images' => 4,
            'news_polls' => 1,
            'pages' => 12,
            'blocks' => 40,
            'content_entries' => 14,
            'content_entry_translations' => 14,
            'photo_albums' => 3,
            'photo_album_images' => 12,
            'photo_album_translations' => 3,
            'videos' => 3,
            'video_translations' => 3,
            'forms' => 3,
            'team_members' => 6,
            'team_member_translations' => 6,
            'menu_items' => 40,
        ];

        $moduleTableMap = [
            'news' => ['news', 'news_translations', 'news_images', 'news_polls'],
            'pages' => ['pages', 'blocks'],
            'entries' => ['content_entries', 'content_entry_translations'],
            'documents' => ['content_entries', 'content_entry_translations'],
            'vacancies' => ['content_entries', 'content_entry_translations'],
            'tenders' => ['content_entries', 'content_entry_translations'],
            'events' => ['content_entries', 'content_entry_translations'],
            'media' => ['photo_albums', 'photo_album_images', 'photo_album_translations', 'videos', 'video_translations'],
            'forms' => ['forms'],
            'team' => ['team_members', 'team_member_translations'],
            'menu' => ['menu_items'],
        ];

        // Проекты — строки pages, отдельной таблицы у них нет: считаем по типу.
        // У каждого проекта своя узбекская запись, как и у страниц.
        if ($selected('projects') && self::tableExists($pdo, 'pages')) {
            $projects = (int) $pdo->query(
                "SELECT COUNT(*) FROM pages WHERE entity_type = 'project'"
            )->fetchColumn();
            if ($projects < 8) {
                $issues[] = "проекты: {$projects}, ожидалось не менее 8";
            }
        }

        foreach ($minimums as $table => $minimum) {
            if (!self::tableExists($pdo, $table)) {
                $issues[] = "отсутствует таблица {$table}";
                continue;
            }
            if (!$all) {
                $relevant = false;
                foreach ($modules as $mod) {
                    if (isset($moduleTableMap[$mod]) && in_array($table, $moduleTableMap[$mod], true)) {
                        $relevant = true;
                        break;
                    }
                }
                if (!$relevant) {
                    continue;
                }
            }
            $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            if ($count < $minimum) {
                $issues[] = "{$table}: {$count}, ожидалось не менее {$minimum}";
            }
        }

        if ($selected('home') && self::tableExists($pdo, 'pages')) {
            $homeCount = (int) $pdo->query('SELECT COUNT(*) FROM pages WHERE is_home = 1')->fetchColumn();
            if ($homeCount !== 1) {
                $issues[] = "главных страниц: {$homeCount}, ожидалась 1";
            }
        }

        if ($selected('pages') && self::tableExists($pdo, 'pages')) {
            $unlinkedPages = (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM pages p
                 LEFT JOIN pages root
                   ON root.id = p.translation_group_id
                  AND root.deleted_at IS NULL
                 WHERE p.deleted_at IS NULL
                   AND (
                       p.translation_group_id IS NULL
                       OR p.translation_group_id = 0
                       OR root.id IS NULL
                   )'
            )->fetchColumn();
            if ($unlinkedPages > 0) {
                $issues[] = "страницы без корректной группы переводов: {$unlinkedPages}";
            }

            $parentedCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM pages WHERE parent_id IS NOT NULL AND deleted_at IS NULL'
            )->fetchColumn();
            if ($parentedCount < 8) {
                $issues[] = "иерархия страниц: {$parentedCount} связей, ожидалось не менее 8";
            }

            $parentedPages = $pdo->query(
                'SELECT id, parent_id FROM pages WHERE parent_id IS NOT NULL AND deleted_at IS NULL'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($parentedPages as $parentedPage) {
                $hierarchyError = \App\Models\Page::validateParent(
                    (int) $parentedPage['parent_id'],
                    (int) $parentedPage['id']
                );
                if ($hierarchyError !== null) {
                    $issues[] = 'страница #' . (int) $parentedPage['id'] . ': ' . $hierarchyError;
                }
            }
        }

        if ($selected('pages') && self::tableExists($pdo, 'blocks')) {
            $knownTypes = BlockTypeRegistry::types();
            $actualTypes = $pdo->query('SELECT DISTINCT type FROM blocks')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($actualTypes as $type) {
                if (!in_array((string) $type, $knownTypes, true)) {
                    $issues[] = "неизвестный тип блока {$type}";
                }
            }

            $mixedPageBlocks = (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM blocks b
                 INNER JOIN pages p ON p.id = b.page_id
                 WHERE p.deleted_at IS NULL
                   AND p.entity_type = \'page\'
                   AND b.lang <> \'\'
                   AND b.lang <> p.lang'
            )->fetchColumn();
            if ($mixedPageBlocks > 0) {
                $issues[] = "блоки привязаны к странице другого языка: {$mixedPageBlocks}";
            }
        }

        if ($selected('home') && self::tableExists($pdo, 'blocks')) {
            $homeStacks = $pdo->query(
                'SELECT b.lang, COUNT(*) AS total
                 FROM blocks b
                 INNER JOIN pages p ON p.id = b.page_id
                 WHERE p.slug = \'home\'
                   AND p.deleted_at IS NULL
                   AND b.lang = p.lang
                   AND b.parent_block_id IS NULL
                 GROUP BY b.lang'
            )->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            foreach (['ru', 'uz'] as $lang) {
                if ((int) ($homeStacks[$lang] ?? 0) < 6) {
                    $issues[] = "главная {$lang}: неполный стек блоков";
                }
            }
        }

        if ($selected('menu') && self::tableExists($pdo, 'menu_items')) {
            if ($selected('pages') && self::tableExists($pdo, 'pages')) {
                // Пункт меню на проект хранится как projects/<slug> — при сверке
                // отрезаем префикс и ищем запись нужного типа.
                // Допускается как точный язык, так и наличие канонического оригинала (ru).
                $brokenTargets = (int) $pdo->query(
                    "SELECT COUNT(*)
                     FROM menu_items mi
                     LEFT JOIN pages p
                       ON mi.url_type = 'page'
                      AND p.slug = IF(mi.url_value LIKE 'projects/%', SUBSTRING(mi.url_value, 10), mi.url_value)
                      AND p.entity_type = IF(mi.url_value LIKE 'projects/%', 'project', 'page')
                      AND (p.lang = mi.lang OR p.lang = 'ru')
                      AND p.status = 'published'
                      AND p.deleted_at IS NULL
                     WHERE mi.url_type = 'page' AND p.id IS NULL"
                )->fetchColumn();
                if ($brokenTargets > 0) {
                    $issues[] = "пункты меню с отсутствующими страницами: {$brokenTargets}";
                }
            }

            foreach (['ru', 'uz'] as $lang) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM menu_items WHERE lang = :lang');
                $stmt->execute([':lang' => $lang]);
                if ((int) $stmt->fetchColumn() < 20) {
                    $issues[] = "меню {$lang}: недостаточно пунктов";
                }
            }
        }

        if ($selected('entries') && self::tableExists($pdo, 'content_entries')) {
            $stmt = $pdo->query('SELECT id, data FROM content_entries');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                json_decode((string) $entry['data'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $issues[] = 'некорректный JSON content_entries #' . (int) $entry['id'];
                }
            }
        }

        if ($all || in_array('assets', $modules, true)) {
            $assetSource = \dirname(__DIR__, 2) . '/database/demo_assets';
            foreach (glob($assetSource . '/*.jpg') ?: [] as $source) {
                $target = self::uploadsDir() . '/' . basename($source);
                if (!is_file($target) || hash_file('sha256', $source) !== hash_file('sha256', $target)) {
                    $issues[] = 'демо-медиа не синхронизировано: ' . basename($source);
                }
            }
        }

        return $issues;
    }

    /** Абсолютный путь каталога публичных загрузок. */
    private static function uploadsDir(): string
    {
        $dir = (string) Config::get('paths.public_uploads', '');
        return $dir !== '' ? rtrim($dir, '/') : \dirname(__DIR__, 2) . '/public/uploads/public';
    }

    /**
     * Копирует демо-изображения из database/demo_assets в каталог публичных
     * загрузок и регистрирует их в медиабиблиотеке (таблица files). Нужно,
     * чтобы демо-главная и карточки показывали реальные картинки после чистой
     * установки (сами загрузки в репозиторий не входят).
     */
    private static function seedAssets(PDO $pdo, array &$c): void
    {
        $src = \dirname(__DIR__, 2) . '/database/demo_assets';
        $dest = self::uploadsDir();
        if (!is_dir($src)) {
            return;
        }
        if (!is_dir($dest) && !mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new \RuntimeException('Не удалось создать каталог демо-медиа: ' . $dest);
        }

        $hasFiles = self::tableExists($pdo, 'files');
        $fileIns = $hasFiles ? $pdo->prepare(
            "INSERT INTO files (original_name, stored_name, mime_type, size, access_type, uploaded_by, created_at)
             SELECT :n, :s, 'image/jpeg', :sz, 'public', NULL, NOW()
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM files WHERE stored_name = :s2)"
        ) : null;

        foreach (glob($src . '/*.jpg') ?: [] as $file) {
            $name = basename($file);
            $target = $dest . '/' . $name;
            $needsCopy = !is_file($target)
                || hash_file('sha256', $file) !== hash_file('sha256', $target);
            if ($needsCopy) {
                if (!copy($file, $target)) {
                    throw new \RuntimeException('Не удалось обновить демо-медиа: ' . $name);
                }
                $c['assets']++;
            }
            if ($fileIns !== null) {
                $fileIns->execute([':n' => $name, ':s' => $name, ':sz' => (int) @filesize($file), ':s2' => $name]);
            }
        }
    }

    /**
     * Демо-главная по эскизу: hero, счётчики, направления, проекты, новости и
     * медиа. Блоки берутся из фикстуры database/demo_assets/home_blocks.json.
     * Идемпотентно: страница создаётся при отсутствии, блоки — только если
     * главная ещё пуста.
     */
    private static function seedHome(PDO $pdo, array &$c): void
    {
        $fixtureDir = \dirname(__DIR__, 2) . '/database/demo_assets';
        $fixtureFiles = [
            'ru' => $fixtureDir . '/home_blocks.json',
            'uz' => $fixtureDir . '/home_blocks_uz.json',
        ];
        $localizedBlocks = [];
        foreach ($fixtureFiles as $lang => $fixture) {
            if (!is_file($fixture)) {
                continue;
            }
            $blocks = json_decode((string) file_get_contents($fixture), true);
            if (is_array($blocks) && $blocks !== []) {
                $localizedBlocks[$lang] = $blocks;
            }
        }
        if ($localizedBlocks === []) {
            return;
        }

        // Главная хранится так же, как остальные переводы: отдельная запись на
        // каждый язык и общая translation_group_id. is_home остаётся только у
        // основной записи, а языковые версии распознаются по общей группе/slug.
        $findDefaultHome = $pdo->prepare(
            "SELECT id, slug, translation_group_id
             FROM pages
             WHERE lang = 'ru'
               AND (is_home = 1 OR slug = 'home')
               AND deleted_at IS NULL
             ORDER BY is_home DESC, id ASC
             LIMIT 1"
        );
        $findDefaultHome->execute();
        $defaultHome = $findDefaultHome->fetch(PDO::FETCH_ASSOC);
        if (!$defaultHome) {
            $insertDefaultHome = $pdo->prepare(
                "INSERT INTO pages
                    (title, slug, meta_title, meta_description, `lead`, status, is_home,
                     layout_type, transparent_header, lang, translation_group_id, created_at)
                 SELECT :title, 'home', :meta_title, :meta_description, :lead, 'published', 1,
                        'no_sidebar', 1, 'ru', NULL, NOW()
                 FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM pages WHERE slug = 'home' AND lang = 'ru'
                 )"
            );
            $insertDefaultHome->execute([
                ':title' => 'Главная',
                ':meta_title' => 'Агентство стратегического развития и реформ',
                ':meta_description' => 'Стратегические инициативы, проекты, новости и аналитические материалы Агентства.',
                ':lead' => 'Стратегия. Реформы. Развитие.',
            ]);
            $c['pages'] += $insertDefaultHome->rowCount();
            $findDefaultHome->execute();
            $defaultHome = $findDefaultHome->fetch(PDO::FETCH_ASSOC);
        }
        if (!$defaultHome) {
            return;
        }

        $homeId = (int) $defaultHome['id'];
        $homeSlug = (string) ($defaultHome['slug'] ?: 'home');
        $pdo->prepare(
            'UPDATE pages
             SET translation_group_id = id
             WHERE id = :id
               AND (translation_group_id IS NULL OR translation_group_id = 0)'
        )->execute([':id' => $homeId]);

        $activeLangs = self::tableExists($pdo, 'languages')
            ? ($pdo->query('SELECT code FROM languages WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN) ?: ['ru', 'uz'])
            : ['ru', 'uz'];

        $homeIds = ['ru' => $homeId];
        $otherLangs = array_values(array_filter($activeLangs, static fn ($l) => $l !== 'ru'));
        foreach ($otherLangs as $otherLang) {
            $findOtherHome = $pdo->prepare(
                'SELECT id
                 FROM pages
                 WHERE lang = :lang
                   AND deleted_at IS NULL
                   AND (translation_group_id = :group_id OR slug = :slug)
                 ORDER BY (translation_group_id = :group_id_order) DESC, id ASC
                 LIMIT 1'
            );
            $findOtherHome->execute([
                ':lang' => $otherLang,
                ':group_id' => $homeId,
                ':slug' => $homeSlug,
                ':group_id_order' => $homeId,
            ]);
            $otherHomeId = $findOtherHome->fetchColumn();
            if ($otherHomeId === false) {
                $hTitle = $otherLang === 'uz' ? 'Bosh sahifa' : ($otherLang === 'en' ? 'Home' : 'Главная');
                $hMeta = $otherLang === 'uz'
                    ? 'Strategik rivojlanish va islohotlar agentligi'
                    : ($otherLang === 'en'
                        ? 'Agency for Strategic Development and Reforms'
                        : 'Агентство стратегического развития и реформ');
                $hDesc = $otherLang === 'uz'
                    ? 'Agentlikning strategik tashabbuslari, loyihalari, yangiliklari va tahliliy materiallari.'
                    : ($otherLang === 'en'
                        ? 'Strategic initiatives, projects, news and analytical materials of the Agency.'
                        : 'Стратегические инициативы, проекты, новости и аналитические материалы Агентства.');
                $hLead = $otherLang === 'uz'
                    ? 'Strategiya. Islohotlar. Taraqqiyot.'
                    : ($otherLang === 'en'
                        ? 'Strategy. Reforms. Development.'
                        : 'Стратегия. Реформы. Развитие.');

                $insertOtherHome = $pdo->prepare(
                    "INSERT INTO pages
                        (title, slug, meta_title, meta_description, `lead`, status, is_home,
                         layout_type, transparent_header, lang, translation_group_id, created_at)
                     SELECT :title, :slug, :meta_title, :meta_description, :lead, 'published', 0,
                            'no_sidebar', 1, :lang, :group_id, NOW()
                     FROM DUAL
                     WHERE NOT EXISTS (
                         SELECT 1 FROM pages WHERE slug = :slug_check AND lang = :lang_check
                     )"
                );
                $insertOtherHome->execute([
                    ':title' => $hTitle,
                    ':slug' => $homeSlug,
                    ':meta_title' => $hMeta,
                    ':meta_description' => $hDesc,
                    ':lead' => $hLead,
                    ':lang' => $otherLang,
                    ':group_id' => $homeId,
                    ':slug_check' => $homeSlug,
                    ':lang_check' => $otherLang,
                ]);
                $c['pages'] += $insertOtherHome->rowCount();
                $findOtherHome->execute([
                    ':lang' => $otherLang,
                    ':group_id' => $homeId,
                    ':slug' => $homeSlug,
                    ':group_id_order' => $homeId,
                ]);
                $otherHomeId = $findOtherHome->fetchColumn();
            }
            if ($otherHomeId !== false) {
                $otherHomeId = (int) $otherHomeId;
                $pdo->prepare(
                    'UPDATE pages
                     SET translation_group_id = :group_id
                     WHERE id = :id
                       AND (translation_group_id IS NULL OR translation_group_id = 0 OR translation_group_id = id)'
                )->execute([':group_id' => $homeId, ':id' => $otherHomeId]);
                $homeIds[$otherLang] = $otherHomeId;
                if (!isset($localizedBlocks[$otherLang])) {
                    $localizedBlocks[$otherLang] = $localizedBlocks['uz'] ?? $localizedBlocks['ru'] ?? [];
                }
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
             VALUES (:pid, :lang, :ty, :ti, :d, :so, 1, NOW())'
        );
        foreach ($localizedBlocks as $lang => $blocks) {
            $localizedHomeId = $homeIds[$lang] ?? null;
            if ($localizedHomeId === null) {
                continue;
            }

            // Не перезаписываем редакторский контент. Исключение — нетронутая
            // стартовая RU-главная из schema.sql, которую демо-комплект заменяет.
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM blocks WHERE page_id = :page_id');
            $countStmt->execute([':page_id' => $localizedHomeId]);
            $count = (int) $countStmt->fetchColumn();
            if ($count > 0) {
                if ($lang !== 'ru' || !self::isUntouchedStarterHome($pdo, $localizedHomeId)) {
                    continue;
                }
                $deleteStarter = $pdo->prepare('DELETE FROM blocks WHERE page_id = :page_id');
                $deleteStarter->execute([':page_id' => $localizedHomeId]);
            }

            // Демо-главной нужна прозрачная шапка поверх hero.
            $pdo->prepare('UPDATE pages SET transparent_header = 1, layout_type = ? WHERE id = ?')
                ->execute(['no_sidebar', $localizedHomeId]);

            // Появление секций — по тем же правилам ритма, что и у остальных
            // демо-страниц: без него главная показывалась одним снимком, и
            // редактор не видел настройки, которая по умолчанию включена.
            // Берём из ритма только появление: фоны и отступы у главной свои.
            $homeTypes = [];
            foreach ($blocks as $b) {
                if (isset($b['type'])) {
                    $homeTypes[] = (string) $b['type'];
                }
            }
            $homeLooks = \App\Core\PagePresets::rhythmFor($homeTypes);

            $order = 1;
            $lookIndex = 0;
            foreach ($blocks as $b) {
                if (!isset($b['type'])) {
                    continue;
                }
                $blockData = is_array($b['data'] ?? null) ? $b['data'] : [];
                if (!array_key_exists('_reveal', $blockData) && isset($homeLooks[$lookIndex]['_reveal'])) {
                    $blockData['_reveal'] = $homeLooks[$lookIndex]['_reveal'];
                }
                $lookIndex++;
                $ins->execute([
                    ':pid' => $localizedHomeId,
                    ':lang' => $lang,
                    ':ty' => (string) $b['type'],
                    ':ti' => (string) ($b['title'] ?? ''),
                    ':d' => json_encode($blockData, JSON_UNESCAPED_UNICODE),
                    ':so' => $order++,
                ]);
                $c['home']++;
            }
        }

        if (isset($localizedBlocks['uz']) && self::tableExists($pdo, 'page_translations')) {
            $translation = $pdo->prepare(
                "INSERT INTO page_translations (page_id, lang, title, meta_title, meta_description, `lead`)
                 VALUES (:page_id, 'uz', :title, :meta_title, :meta_description, :lead)
                 ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    meta_title = VALUES(meta_title),
                    meta_description = VALUES(meta_description),
                    `lead` = VALUES(`lead`)"
            );
            $translation->execute([
                ':page_id' => $homeId,
                ':title' => 'Bosh sahifa',
                ':meta_title' => 'Strategik rivojlanish va islohotlar agentligi',
                ':meta_description' => 'Agentlikning strategik tashabbuslari, loyihalari, yangiliklari va tahliliy materiallari.',
                ':lead' => 'Strategiya. Islohotlar. Taraqqiyot.',
            ]);
        }
    }

    private static function seedNews(PDO $pdo, array &$c): void
    {
        self::seedFlagshipNews($pdo, $c);

        $news = [
            [
                'title' => 'Представлена цифровая платформа мониторинга реформ',
                'slug' => 'platforma-monitoringa-reform',
                'excerpt' => 'Новая платформа объединяет ключевые показатели Стратегии «Узбекистан–2030» и позволяет отслеживать достижение результатов.',
                'category' => 'Цифровизация',
                'image' => '/uploads/public/demo-agency-hero.jpg',
                'hashtags' => '#Узбекистан2030 #цифровизация #реформы',
                'layout' => 'standard',
                'uz_title' => 'Islohotlarni monitoring qilish raqamli platformasi taqdim etildi',
                'uz_excerpt' => 'Yangi platforma «O‘zbekiston–2030» strategiyasining asosiy ko‘rsatkichlarini birlashtiradi va natijalar ijrosini kuzatish imkonini beradi.',
                'uz_category' => 'Raqamlashtirish',
                'uz_hashtags' => '#O‘zbekiston2030 #raqamlashtirish',
            ],
            [
                'title' => 'Обсуждены приоритеты устойчивого регионального развития',
                'slug' => 'regionalnoe-razvitie-prioritety',
                'excerpt' => 'Эксперты и представители регионов рассмотрели проекты инфраструктуры, занятости и развития человеческого капитала.',
                'category' => 'Региональное развитие',
                'image' => '/uploads/public/demo-urban-development.jpg',
                'hashtags' => '#регионы #инфраструктура #развитие',
                'layout' => 'side_image',
                'uz_title' => 'Hududlarni barqaror rivojlantirish ustuvor yo‘nalishlari muhokama qilindi',
                'uz_excerpt' => 'Ekspertlar va hududlar vakillari infratuzilma, bandlik va inson kapitalini rivojlantirish loyihalarini ko‘rib chiqdilar.',
                'uz_category' => 'Hududiy rivojlanish',
                'uz_hashtags' => '#hududlar #infratuzilma',
            ],
            [
                'title' => 'Опубликован аналитический обзор социально-экономической динамики',
                'slug' => 'analiticheskiy-obzor-dinamiki',
                'excerpt' => 'Обзор содержит ключевые тенденции, сценарные оценки и рекомендации для дальнейшего повышения устойчивости экономики.',
                'category' => 'Аналитика',
                'image' => '/uploads/public/demo-strategy-meeting.jpg',
                'hashtags' => '#аналитика #экономика #прогноз',
                'layout' => 'premium',
                'uz_title' => 'Ijtimoiy-iqtisodiy dinamika bo‘yicha tahliliy sharh e’lon qilindi',
                'uz_excerpt' => 'Sharh asosiy tendensiyalar, ssenariy baholari va iqtisodiyot barqarorligini oshirish bo‘yicha tavsiyalarni qamrab oladi.',
                'uz_category' => 'Tahlil',
                'uz_hashtags' => '#tahlil #iqtisodiyot',
            ],
            [
                'title' => 'Расширяется портфель проектов зелёной экономики',
                'slug' => 'portfel-zelenoy-ekonomiki',
                'excerpt' => 'В портфель включены инициативы в сфере возобновляемой энергетики, энергоэффективности и устойчивой инфраструктуры.',
                'category' => 'Зелёная экономика',
                'image' => '/uploads/public/demo-green-energy.jpg',
                'hashtags' => '#зелёнаяэкономика #энергетика #ESG',
                'layout' => 'gallery',
                // Слайдер новости — единственное место, где подпись и автор
                // снимка видны текстом. Без снимков демо этого не показывало.
                'gallery' => [
                    ['/uploads/public/demo-green-energy.jpg', 'Ввод в эксплуатацию объекта возобновляемой энергетики', 'пресс-служба Агентства'],
                    ['/uploads/public/demo-urban-development.jpg', 'Устойчивая городская инфраструктура', 'пресс-служба Агентства'],
                    ['/uploads/public/demo-strategy-meeting.jpg', 'Обсуждение портфеля проектов', ''],
                ],
                'uz_title' => 'Yashil iqtisodiyot loyihalari portfeli kengaymoqda',
                'uz_excerpt' => 'Portfelga qayta tiklanuvchi energiya, energiya samaradorligi va barqaror infratuzilma tashabbuslari kiritildi.',
                'uz_category' => 'Yashil iqtisodiyot',
                'uz_hashtags' => '#yashiliqtisodiyot #energetika',
            ],
            [
                'title' => 'Открыт приём заявок в экспертный кадровый резерв',
                'slug' => 'ekspertnyy-kadrovyy-rezerv',
                'excerpt' => 'К участию приглашаются специалисты в области стратегического планирования, анализа данных и управления проектами.',
                'category' => 'Карьера',
                'image' => '/uploads/public/hero-demo-g2.jpg',
                'hashtags' => '#карьера #эксперты #вакансии',
                'layout' => 'standard',
                'uz_title' => 'Ekspert kadrlar zaxirasiga arizalar qabul qilinmoqda',
                'uz_excerpt' => 'Strategik rejalashtirish, ma’lumotlar tahlili va loyihalarni boshqarish sohasidagi mutaxassislar taklif etiladi.',
                'uz_category' => 'Karyera',
                'uz_hashtags' => '#karyera #ekspertlar',
            ],
            // Седьмая новость нужна раскладке «мозаика»: она строится как
            // 1 + 2 + 4, и при шести записях нижний ряд оставался неполным —
            // на свежей установке блок выглядел бы недоделанным.
            [
                'title' => 'Подписаны новые соглашения о партнёрстве с зарубежными организациями',
                'slug' => 'soglasheniya-o-partnerstve',
                'excerpt' => 'Договорённости касаются обмена опытом в стратегическом планировании и совместных исследований.',
                'category' => 'Международное сотрудничество',
                'image' => '/uploads/public/hero-demo-g3.jpg',
                'hashtags' => '#партнёрство #сотрудничество',
                'layout' => 'standard',
                'uz_title' => 'Xorijiy tashkilotlar bilan yangi hamkorlik bitimlari imzolandi',
                'uz_excerpt' => 'Kelishuvlar strategik rejalashtirish sohasidagi tajriba almashish va qo‘shma tadqiqotlarga taalluqli.',
                'uz_category' => 'Xalqaro hamkorlik',
                'uz_hashtags' => '#hamkorlik #xalqaro',
            ],
        ];
        // Рубрика демо-новостей — категория, а не текст в бейдже: справочник
        // нужен свежей установке сразу, иначе фильтр ленты нечем наполнить.
        //
        // Заводим только те рубрики, которым действительно найдётся новость.
        // Иначе повторный запуск на установке, где демо-новости уже есть,
        // добавлял рубрики-пустышки: сами новости пропускаются (NOT EXISTS),
        // а рядом с уже перенесённой «Мероприятие» появлялись «Мероприятия» и
        // «Карьера» без единой записи.
        $categoryIds = self::seedNewsCategories($pdo, self::newsNeedingCategory($pdo, $news), $c);

        $ins = $pdo->prepare(
            "INSERT INTO news (title, slug, excerpt, category_id, content, image, hashtags, layout_type, sidebar_layout, meta_title, meta_description, status, published_at, lang, created_at)
             SELECT :t, :s, :e, :cat, :co, :img, :hashtags, :layout, 'right_sidebar', :mt, :md, 'published', NOW() - INTERVAL :d DAY, 'ru', NOW()
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM news WHERE slug = :s2)"
        );
        foreach ($news as $i => $n) {
            // Лид не повторяем в тексте: он уже стоит в шапке новости, и один
            // и тот же абзац выглядел на странице дважды.
            $content = '<p>Материал демонстрирует полноценную публикацию: структурированный текст, тематическую обложку, метаданные и связанную узбекскую версию.</p>'
                . '<h2>Основные направления</h2><ul><li>измеримые цели и показатели;</li><li>межведомственная координация;</li><li>открытость результатов для общества.</li></ul>';
            $ins->execute([
                ':t' => $n['title'],
                ':s' => $n['slug'],
                ':e' => $n['excerpt'],
                ':cat' => $categoryIds[$n['category']] ?? null,
                ':co' => $content,
                ':img' => $n['image'],
                ':hashtags' => $n['hashtags'],
                ':layout' => $n['layout'],
                ':mt' => $n['title'] . ' — Агентство',
                ':md' => $n['excerpt'],
                ':d' => $i + 2,
                ':s2' => $n['slug'],
            ]);
            $c['news'] += $ins->rowCount();

            if ($ins->rowCount() === 0 && isset($categoryIds[$n['category']])) {
                self::attachCategoryToExisting(
                    $pdo,
                    (string) $n['slug'],
                    $categoryIds[$n['category']],
                    [(string) $n['category'], (string) $n['uz_category']]
                );
            }

            if (!empty($n['gallery']) && self::tableExists($pdo, 'news_images')) {
                $galleryIdStmt = $pdo->prepare("SELECT id FROM news WHERE slug = :slug AND lang = 'ru' LIMIT 1");
                $galleryIdStmt->execute([':slug' => $n['slug']]);
                $galleryNewsId = $galleryIdStmt->fetchColumn();
                if ($galleryNewsId !== false) {
                    self::seedNewsGallery($pdo, (int) $galleryNewsId, $n['gallery'], $n['title']);
                }
            }

            if (self::tableExists($pdo, 'news_translations')) {
                $idStmt = $pdo->prepare("SELECT id FROM news WHERE slug = :slug AND lang = 'ru' LIMIT 1");
                $idStmt->execute([':slug' => $n['slug']]);
                $newsId = $idStmt->fetchColumn();
                if ($newsId !== false) {
                    $trans = $pdo->prepare(
                        "INSERT INTO news_translations
                            (news_id, lang, title, excerpt, content, hashtags, meta_title, meta_description)
                         SELECT :nid, 'uz', :t, :e, :co, :hashtags, :mt, :md
                         FROM DUAL
                         WHERE NOT EXISTS (
                             SELECT 1 FROM news_translations WHERE news_id = :nid2 AND lang = 'uz'
                         )"
                    );
                    $uzContent = '<p>Material to‘liq nashr imkoniyatlarini namoyish etadi: tuzilgan matn, mavzuli muqova, metadata va ikki tilli kontent.</p>';
                    $trans->execute([
                        ':nid' => (int) $newsId,
                        ':t' => $n['uz_title'],
                        ':e' => $n['uz_excerpt'],
                        ':co' => $uzContent,
                        ':hashtags' => $n['uz_hashtags'],
                        ':mt' => $n['uz_title'] . ' — Agentlik',
                        ':md' => $n['uz_excerpt'],
                        ':nid2' => (int) $newsId,
                    ]);
                }
            }
        }
    }

    /**
     * Снимки галереи новости с подписью и автором. Колонки появились
     * миграцией подписей, поэтому наличие проверяем: демо ставят и на базу,
     * где миграция ещё не применена.
     *
     * @param list<array{0:string,1:string,2:string}> $images путь, подпись, автор
     */
    /**
     * Проставляет рубрику демо-новости, которая уже есть в базе, но осталась
     * без категории (установка старше категорий). Заполняется только пустое
     * поле — чужой выбор рубрики демо-контент не переписывает.
     *
     * Заодно снимается старый бейдж, если в нём лежит та же рубрика: раньше он
     * её и изображал, а теперь карточка показала бы рубрику дважды — один раз
     * категорией, второй раз меткой. Сравнение точное и только с названиями из
     * фикстуры: собственную метку редактора («Важно») это не трогает.
     *
     * @param list<string> $rubricNames названия рубрики на всех языках фикстуры
     */
    private static function attachCategoryToExisting(PDO $pdo, string $slug, int $categoryId, array $rubricNames): void
    {
        $idStmt = $pdo->prepare("SELECT id FROM news WHERE slug = :slug AND lang = 'ru' AND deleted_at IS NULL LIMIT 1");
        $idStmt->execute([':slug' => $slug]);
        $newsId = $idStmt->fetchColumn();
        if ($newsId === false) {
            return;
        }
        $newsId = (int) $newsId;

        // Языковые версии — отдельные записи одной группы: рубрика у группы
        // общая, поэтому проставляем её всем, а не только русской записи.
        $groupSql = '(n.id = :id OR n.translation_group_id = :gid)';
        $pdo->prepare(
            "UPDATE news n SET n.category_id = :cat
             WHERE {$groupSql} AND n.category_id IS NULL AND n.deleted_at IS NULL"
        )->execute([':cat' => $categoryId, ':id' => $newsId, ':gid' => $newsId]);

        $names = array_values(array_filter(array_map('trim', $rubricNames), static fn (string $v): bool => $v !== ''));
        if ($names === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        $pdo->prepare(
            "UPDATE news n SET n.badge = NULL
             WHERE (n.id = ? OR n.translation_group_id = ?) AND n.deleted_at IS NULL AND n.badge IN ({$placeholders})"
        )->execute([$newsId, $newsId, ...$names]);

        if (self::tableExists($pdo, 'news_translations')) {
            $pdo->prepare(
                "UPDATE news_translations SET badge = NULL
                 WHERE news_id = ? AND badge IN ({$placeholders})"
            )->execute([$newsId, ...$names]);
        }
    }

    /**
     * Отбирает записи фикстуры, которым рубрика действительно нужна: новость
     * ещё не создана либо создана, но осталась без категории. Для остальных
     * заводить рубрику нельзя — она повиснет пустой.
     *
     * @param array<int, array<string, mixed>> $news
     * @return array<int, array<string, mixed>>
     */
    private static function newsNeedingCategory(PDO $pdo, array $news): array
    {
        if (!self::tableExists($pdo, 'news_categories')) {
            return [];
        }

        $check = $pdo->prepare(
            "SELECT category_id FROM news WHERE slug = :slug AND lang = 'ru' AND deleted_at IS NULL LIMIT 1"
        );

        return array_values(array_filter($news, static function (array $item) use ($check): bool {
            $check->execute([':slug' => (string) ($item['slug'] ?? '')]);
            $row = $check->fetch();

            // Новости нет — она будет создана с рубрикой; есть без рубрики —
            // рубрику проставим; есть с рубрикой — не трогаем.
            return $row === false || $row['category_id'] === null;
        }));
    }

    /**
     * Категории демо-новостей: справочник рубрик с узбекскими переводами.
     * Повторный запуск ничего не дублирует — существующие берутся по slug.
     *
     * @param array<int, array<string, mixed>> $news
     * @param array<string, int> $c счётчики созданного (по ссылке)
     * @return array<string, int> название рубрики → id категории
     */
    private static function seedNewsCategories(PDO $pdo, array $news, array &$c): array
    {
        if (!self::tableExists($pdo, 'news_categories')) {
            return [];
        }

        $map = [];
        $sortOrder = 0;
        foreach ($news as $item) {
            $name = trim((string) ($item['category'] ?? ''));
            if ($name === '' || isset($map[$name])) {
                continue;
            }

            $sortOrder += 10;
            $slug = \App\Core\Slug::make($name);
            $existing = $pdo->prepare('SELECT id FROM news_categories WHERE slug = :slug LIMIT 1');
            $existing->execute([':slug' => $slug]);
            $id = $existing->fetchColumn();

            if ($id === false) {
                $pdo->prepare(
                    'INSERT INTO news_categories (name, slug, is_active, sort_order) VALUES (:n, :s, 1, :o)'
                )->execute([':n' => $name, ':s' => $slug, ':o' => $sortOrder]);
                $id = $pdo->lastInsertId();
                $c['news_categories'] = ($c['news_categories'] ?? 0) + 1;
            }

            $map[$name] = (int) $id;

            $uzName = trim((string) ($item['uz_category'] ?? ''));
            if ($uzName !== '' && self::tableExists($pdo, 'news_category_translations')) {
                $pdo->prepare(
                    'INSERT INTO news_category_translations (category_id, lang, name) VALUES (:c, :l, :n)
                     ON DUPLICATE KEY UPDATE name = VALUES(name)'
                )->execute([':c' => (int) $id, ':l' => 'uz', ':n' => $uzName]);
            }
        }

        return $map;
    }

    private static function seedNewsGallery(PDO $pdo, int $newsId, array $images, string $altBase): void
    {
        $withCaptions = self::columnExists($pdo, 'news_images', 'caption')
            && self::columnExists($pdo, 'news_images', 'credit');
        $ins = $pdo->prepare(
            $withCaptions
                ? 'INSERT INTO news_images (news_id, path, alt_text, caption, credit, sort_order, created_at)
                   SELECT :nid, :p, :a, :cap, :cr, :o, NOW()
                   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM news_images WHERE news_id = :nid2 AND path = :p2)'
                : 'INSERT INTO news_images (news_id, path, alt_text, sort_order, created_at)
                   SELECT :nid, :p, :a, :o, NOW()
                   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM news_images WHERE news_id = :nid2 AND path = :p2)'
        );
        foreach ($images as $order => [$path, $caption, $credit]) {
            $params = [':nid' => $newsId, ':p' => $path, ':a' => $altBase, ':o' => $order, ':nid2' => $newsId, ':p2' => $path];
            if ($withCaptions) {
                $params[':cap'] = $caption;
                $params[':cr'] = $credit;
            }
            $ins->execute($params);
        }
    }

    /**
     * Флагманская демо-новость в «премиум»-макете (по эскизу детальной
     * страницы): бейдж, ключевые тезисы, карточка мероприятия, цитата,
     * документы и фотогалерея. Показывает редактору все возможности
     * медиа-движка новостей сразу после установки.
     */
    private static function seedFlagshipNews(PDO $pdo, array &$c): void
    {
        $slug = 'zasedanie-strategiya-2030';
        $docs = [
            ['title' => 'Пресс-релиз по итогам заседания', 'meta' => 'PDF · 245 КБ', 'url' => '/catalog/documenty'],
            ['title' => 'Презентация: ход реализации Стратегии', 'meta' => 'PDF · 1,2 МБ', 'url' => '/catalog/documenty'],
        ];
        $timeline = [
            ['date' => '09:30', 'title' => 'Открытие заседания', 'text' => 'Представлена повестка и ожидаемые результаты.'],
            ['date' => '10:15', 'title' => 'Отчёты по направлениям', 'text' => 'Рассмотрена динамика ключевых показателей.'],
            ['date' => '12:00', 'title' => 'Приняты решения', 'text' => 'Определены ответственные исполнители и контрольные сроки.'],
        ];
        $content = '<p>В Агентстве стратегического развития и реформ Республики Узбекистан состоялось расширенное заседание, посвящённое вопросам реализации Стратегии «Узбекистан–2030».</p>'
            . '<p>В заседании приняли участие руководители профильных министерств и ведомств, представители регионов и эксперты. Участники обсудили ход выполнения ключевых инициатив, определили приоритеты на предстоящий период и утвердили конкретные меры по их реализации.</p>'
            . '<blockquote><p>Наша задача — обеспечить эффективную реализацию всех намеченных инициатив и достичь конкретных результатов, которые ощутит каждый гражданин нашей страны.</p><cite>Директор Агентства</cite></blockquote>'
            . '<h2>Основные вопросы повестки</h2>'
            . '<ul><li>Реализация приоритетных направлений Стратегии «Узбекистан–2030»</li>'
            . '<li>Развитие «зелёной» экономики и энергетики</li>'
            . '<li>Инвестиционная и промышленная политика</li>'
            . '<li>Развитие образования, науки и инноваций</li>'
            . '<li>Цифровая трансформация и электронное правительство</li></ul>'
            . '<p>По итогам заседания ответственным ведомствам и регионам даны поручения по ускорению реализации проектов и обеспечению своевременного достижения ключевых показателей.</p>';

        // Рубрика — категория «Мероприятия»; метка остаётся меткой и показывает
        // редактору, как выглядит выделенная новость. Рубрику заводим только
        // если этой новости ещё нет или она осталась без категории — иначе
        // повторный запуск плодит пустые рубрики.
        $categoryId = self::seedNewsCategories(
            $pdo,
            self::newsNeedingCategory($pdo, [
                ['slug' => $slug, 'category' => 'Мероприятия', 'uz_category' => 'Tadbirlar'],
            ]),
            $c
        )['Мероприятия'] ?? null;

        $ins = $pdo->prepare(
            "INSERT INTO news (title, slug, excerpt, badge, badge_color, category_id, content, image, hashtags, key_points, event_meta, timeline_json, docs, source_note, views, layout_type, sidebar_layout, meta_title, meta_description, status, published_at, lang, created_at)
             SELECT :t, :s, :e, :b, :bc, :cat, :co, :img, :hashtags, :kp, :em, :timeline, :dc, :sn, 1284, 'premium', 'right_sidebar', :mt, :md, 'published', NOW() - INTERVAL 1 DAY, 'ru', NOW()
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM news WHERE slug = :s2)"
        );
        $ins->execute([
            ':t' => 'Заседание по вопросам реализации Стратегии «Узбекистан–2030»',
            ':s' => $slug,
            ':e' => 'Обсуждены ключевые приоритеты и ход реализации стратегических инициатив, направленных на устойчивое развитие страны и повышение благосостояния населения.',
            ':b' => 'Важно',
            ':bc' => '#c0392b',
            ':cat' => $categoryId,
            ':co' => $content,
            ':img' => '/uploads/public/demo-strategy-meeting.jpg',
            ':hashtags' => '#Узбекистан2030 #стратегия #реформы #развитие',
            ':kp' => "Рассмотрены приоритетные направления Стратегии «Узбекистан–2030»\nПроанализирован прогресс реализации ключевых инициатив\nУтверждены дальнейшие шаги и ответственные исполнители\nОсобое внимание уделено инвестициям, инновациям и человеческому капиталу",
            ':em' => "Дата: 20 мая 2026 года\nФормат: расширенное заседание\nУчастники: министерства, ведомства, регионы",
            ':timeline' => json_encode($timeline, JSON_UNESCAPED_UNICODE),
            ':dc' => json_encode($docs, JSON_UNESCAPED_UNICODE),
            ':sn' => 'Подготовлено пресс-службой Агентства',
            ':mt' => 'Реализация Стратегии «Узбекистан–2030» — итоги заседания',
            ':md' => 'Ключевые решения расширенного заседания по реализации Стратегии «Узбекистан–2030».',
            ':s2' => $slug,
        ]);
        $c['news'] += $ins->rowCount();

        if ($ins->rowCount() === 0 && $categoryId !== null) {
            self::attachCategoryToExisting($pdo, $slug, $categoryId, ['Мероприятие', 'Мероприятия', 'Tadbirlar']);
        }

        // Фотогалерея новости — если таблица есть и запись только что создана.
        $newsId = $pdo->prepare('SELECT id FROM news WHERE slug = :s LIMIT 1');
        $newsId->execute([':s' => $slug]);
        $nid = $newsId->fetchColumn();
        if ($nid !== false) {
            $uzDocs = [
                ['title' => 'Yig‘ilish yakunlari bo‘yicha press-reliz', 'meta' => 'PDF · 245 KB', 'url' => '/catalog/documenty'],
                ['title' => 'Taqdimot: Strategiyani amalga oshirish borishi', 'meta' => 'PDF · 1,2 MB', 'url' => '/catalog/documenty'],
            ];
            $uzContent = '<p>O‘zbekiston Respublikasi Strategik rejalashtirish va islohotlar agentligida «O‘zbekiston–2030» Strategiyasini amalga oshirish masalalariga bag‘ishlangan kengaytirilgan yig‘ilish bo‘lib o‘tdi.</p>'
                . '<p>Yig‘ilishda tegishli vazirlik va idoralar rahbarlari, hududlar vakillari hamda ekspertlar ishtirok etdilar. Ishtirokchilar ustuvor tashabbuslarning bajarilish borishini muhokama qildilar.</p>'
                . '<blockquote><p>Vazifamiz — barcha belgilangan tashabbuslarning samarali amalga oshirilishini ta’minlash va har bir fuqaro sezadigan aniq natijalarga erishishdir.</p><cite>Agentlik direktori</cite></blockquote>';

            if (self::tableExists($pdo, 'news_translations')) {

                $transIns = $pdo->prepare(
                    'INSERT INTO news_translations (news_id, lang, title, excerpt, content, key_points, event_meta, docs, poll_question, poll_options_json)
                     SELECT :nid, "uz", :t, :e, :co, :kp, :em, :dc, :pq, :po
                     FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM news_translations WHERE news_id = :nid2 AND lang = "uz")'
                );
                $transIns->execute([
                    ':nid' => (int) $nid,
                    ':t' => '«O‘zbekiston–2030» Strategiyasini amalga oshirish masalalari bo‘yicha yig‘ilish',
                    ':e' => 'Mamlakatni barqaror rivojlantirish va aholi farovonligini oshirishga qaratilgan strategik tashabbuslarni amalga oshirish borishi muhokama qilindi.',
                    ':co' => $uzContent,
                    ':kp' => "«O‘zbekiston–2030» Strategiyasining ustuvor yo‘nalishlari ko‘rib chiqildi\nAsosiy tashabbuslar ijrosi tahlil qilindi\nKelgusi qadamlar va mas’ul ijrochilar tasdiqlandi",
                    ':em' => "Sana: 20-may 2026-yil\nShakl: kengaytirilgan yig‘ilish\nIshtirokchilar: vazirliklar, idoralar, hududlar",
                    ':dc' => json_encode($uzDocs, JSON_UNESCAPED_UNICODE),
                    ':pq' => 'Strategiya ijrosidagi ustuvor yo‘nalishni qo‘llab-quvvatlaysizmi?',
                    ':po' => json_encode(['Ha, to‘liq', 'Qisman', 'Qo‘shimcha takliflarim bor'], JSON_UNESCAPED_UNICODE),
                    ':nid2' => (int) $nid,
                ]);
            }

            $cols = $pdo->query("SHOW COLUMNS FROM news")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('translation_group_id', $cols, true)) {
                $existUz = $pdo->prepare("SELECT id FROM news WHERE translation_group_id = :gid AND lang = 'uz' LIMIT 1");
                $existUz->execute([':gid' => (int) $nid]);
                if ($existUz->fetchColumn() === false) {
                    $uzIns = $pdo->prepare(
                        "INSERT INTO news (title, slug, excerpt, badge, badge_color, category_id, content, image, hashtags, key_points, event_meta, docs, status, published_at, lang, translation_group_id, created_at)
                         VALUES (:t, 'strategiya-uzbekistan-2030-uz', :e, :b, :bc, :cat, :c, '/uploads/public/demo-strategy-meeting.jpg', :tags, :kp, :em, :dc, 'published', NOW(), 'uz', :gid, NOW())"
                    );
                    $uzIns->execute([
                        ':t' => '«O‘zbekiston–2030» Strategiyasini amalga oshirish masalalari bo‘yicha yig‘ilish',
                        ':e' => 'Mamlakatni barqaror rivojlantirish va aholi farovonligini oshirishga qaratilgan strategik tashabbuslarni amalga oshirish borishi muhokama qilindi.',
                        ':b' => 'Muhim',
                        ':bc' => '#c0392b',
                        ':cat' => $categoryId,
                        // Свои хештеги у языковой версии: в пост Telegram
                        // уходят теги всех версий, без повторов.
                        ':tags' => '#o‘zbekiston2030 #strategiya',
                        ':c' => $uzContent,
                        ':kp' => "«O‘zbekiston–2030» Strategiyasining ustuvor yo‘nalishlari ko‘rib chiqildi\nAsosiy tashabbuslar ijrosi tahlil qilindi\nKelgusi qadamlar va mas’ul ijrochilar tasdiqlandi",
                        ':em' => "Sana: 20-may 2026-yil\nShakl: kengaytirilgan yig‘ilish\nIshtirokchilar: vazirliklar, idoralar, hududlar",
                        ':dc' => json_encode($uzDocs, JSON_UNESCAPED_UNICODE),
                        ':gid' => (int) $nid,
                    ]);
                }
            }

            if (self::tableExists($pdo, 'news_images')) {
                // Подпись и автор снимка: показываем редактору, что эти поля
                // существуют — они видны под фото на сайте и уходят в пост
                // Telegram. Колонки появились миграцией, поэтому проверяем.
                $withCaptions = self::columnExists($pdo, 'news_images', 'caption')
                    && self::columnExists($pdo, 'news_images', 'credit');
                $imgIns = $pdo->prepare(
                    $withCaptions
                        ? 'INSERT INTO news_images (news_id, path, alt_text, caption, credit, sort_order, created_at)
                           SELECT :nid, :p, :a, :cap, :cr, :o, NOW()
                           FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM news_images WHERE news_id = :nid2 AND path = :p2)'
                        : 'INSERT INTO news_images (news_id, path, alt_text, sort_order, created_at)
                           SELECT :nid, :p, :a, :o, NOW()
                           FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM news_images WHERE news_id = :nid2 AND path = :p2)'
                );
                $gallery = [
                    ['/uploads/public/demo-strategy-meeting.jpg', 'Расширенное заседание по Стратегии «Узбекистан–2030»', 'пресс-служба Агентства'],
                    ['/uploads/public/demo-agency-hero.jpg', 'Штаб-квартира Агентства', 'пресс-служба Агентства'],
                    ['/uploads/public/demo-urban-development.jpg', 'Городская среда: проекты развития регионов', ''],
                    ['/uploads/public/demo-green-energy.jpg', '', 'пресс-служба Агентства'],
                ];
                foreach ($gallery as $i => [$path, $caption, $credit]) {
                    $params = [':nid' => (int) $nid, ':p' => $path, ':a' => 'Заседание по Стратегии «Узбекистан–2030»', ':o' => $i, ':nid2' => (int) $nid, ':p2' => $path];
                    if ($withCaptions) {
                        $params[':cap'] = $caption;
                        $params[':cr'] = $credit;
                    }
                    $imgIns->execute($params);
                }
            }

            if (self::tableExists($pdo, 'news_polls')) {
                $pollIns = $pdo->prepare(
                    "INSERT INTO news_polls (news_id, question, options_json, created_at)
                     SELECT :nid, :question, :options, NOW()
                     FROM DUAL
                     WHERE NOT EXISTS (SELECT 1 FROM news_polls WHERE news_id = :nid2)"
                );
                $pollIns->execute([
                    ':nid' => (int) $nid,
                    ':question' => 'Какое направление Стратегии наиболее важно для устойчивого развития?',
                    ':options' => json_encode(
                        ['Человеческий капитал', 'Экономический рост', 'Зелёная экономика', 'Цифровая трансформация'],
                        JSON_UNESCAPED_UNICODE
                    ),
                    ':nid2' => (int) $nid,
                ]);
            }
        }
    }

    private static function seedEntries(PDO $pdo, array &$c): void
    {
        $ins = $pdo->prepare(
            "INSERT INTO content_entries (type_id, title, slug, status, data, created_at)
             SELECT :tid, :t, :s, 'published', :d, NOW()
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM content_entries WHERE type_id = :tid2 AND slug = :s2)"
        );
        $byType = [
            'documenty' => [
                ['Методология мониторинга Стратегии «Узбекистан–2030»', 'metodologiya-monitoringa-2030', ['doc_number' => 'ММ-2030', 'doc_date' => '2026-07-15', 'category' => 'Методология', 'summary' => 'Единые подходы к оценке достижения целей, индикаторов и результатов реформ.', 'file' => '/catalog/documenty/metodologiya-monitoringa-2030']],
                ['Аналитический отчёт о ходе структурных реформ', 'otchet-strukturnye-reformy', ['doc_number' => 'АО-07/26', 'doc_date' => '2026-07-01', 'category' => 'Аналитические отчёты', 'summary' => 'Результаты мониторинга структурных преобразований и рекомендации по дальнейшим шагам.', 'file' => '/catalog/documenty/otchet-strukturnye-reformy']],
                ['Регламент межведомственной координации', 'reglament-koordinacii', ['doc_number' => 'РК-12', 'doc_date' => '2026-06-20', 'category' => 'Регламенты', 'summary' => 'Порядок обмена данными, согласования решений и контроля исполнения.', 'file' => '/catalog/documenty/reglament-koordinacii']],
                ['Обзор международного опыта стратегического планирования', 'obzor-mezhdunarodnogo-opyta', ['doc_number' => 'ОМО-04', 'doc_date' => '2026-06-05', 'category' => 'Обзоры', 'summary' => 'Сравнение современных моделей стратегического управления и оценки реформ.', 'file' => '/catalog/documenty/obzor-mezhdunarodnogo-opyta']],
            ],
            'vakansii' => [
                ['Ведущий специалист отдела ИТ', 'vedushchiy-it', ['department' => 'Отдел информационных технологий', 'salary' => 'по договорённости', 'deadline' => '2026-08-31', 'requirements' => 'Высшее образование, опыт от 3 лет, знание PHP/MySQL.', 'duties' => 'Сопровождение и развитие информационных систем.']],
                ['Юрисконсульт', 'yuriskonsult', ['department' => 'Юридический отдел', 'salary' => 'от 8 000 000 сум', 'deadline' => '2026-08-20', 'requirements' => 'Высшее юридическое образование, опыт от 2 лет.', 'duties' => 'Правовое сопровождение деятельности организации.']],
                ['Специалист по кадрам', 'specialist-kadry', ['department' => 'Отдел кадров', 'salary' => 'от 6 000 000 сум', 'deadline' => '2026-09-10', 'requirements' => 'Опыт кадрового делопроизводства.', 'duties' => 'Ведение кадрового учёта и документации.']],
                ['Пресс-секретарь', 'press-sekretar', ['department' => 'Пресс-служба', 'salary' => 'по итогам собеседования', 'deadline' => '2026-08-05', 'requirements' => 'Опыт в СМИ или PR, грамотная речь.', 'duties' => 'Взаимодействие со СМИ, ведение новостей сайта.']],
            ],
            'tendery' => [
                ['Развитие аналитической платформы мониторинга', 'platforma-monitoringa-zakupka', ['tender_number' => 'T-2026-031', 'budget' => 'по итогам конкурса', 'start_date' => '2026-07-10', 'deadline' => '2026-08-20', 'summary' => 'Разработка модулей визуализации показателей и межведомственного обмена данными.', 'file' => '/catalog/tendery/platforma-monitoringa-zakupka']],
                ['Исследование социально-экономической динамики регионов', 'issledovanie-regionov', ['tender_number' => 'T-2026-034', 'budget' => 'по итогам конкурса', 'start_date' => '2026-07-18', 'deadline' => '2026-09-01', 'summary' => 'Комплексное исследование факторов роста и качества жизни в регионах.', 'file' => '/catalog/tendery/issledovanie-regionov']],
                ['Организация международного экспертного форума', 'ekspertnyy-forum', ['tender_number' => 'T-2026-038', 'budget' => 'по итогам конкурса', 'start_date' => '2026-07-25', 'deadline' => '2026-09-15', 'summary' => 'Организационное и техническое сопровождение экспертного форума.', 'file' => '/catalog/tendery/ekspertnyy-forum']],
            ],
            'meropriyatiya' => [
                ['Открытая презентация системы мониторинга Стратегии', 'prezentaciya-monitoringa-strategii', ['event_date' => '2026-09-12', 'event_time' => '10:00', 'location' => 'Ташкент, конференц-зал Агентства', 'banner_image' => '/uploads/public/demo-strategy-meeting.jpg', 'summary' => 'Презентация цифровой системы мониторинга целей и показателей Стратегии «Узбекистан–2030».']],
                ['Экспертный диалог по региональному развитию', 'dialog-regionalnoe-razvitie', ['event_date' => '2026-10-03', 'event_time' => '15:00', 'location' => 'Гибридный формат', 'banner_image' => '/uploads/public/demo-urban-development.jpg', 'summary' => 'Обсуждение новых подходов к развитию регионов и оценке качества государственных программ.']],
                ['Форум стратегических инициатив', 'forum-strategicheskih-iniciativ', ['event_date' => '2026-11-18', 'event_time' => '09:30', 'location' => 'Ташкент', 'banner_image' => '/uploads/public/demo-agency-hero.jpg', 'summary' => 'Площадка для обмена опытом между государственными органами, экспертами и международными партнёрами.']],
            ],
        ];
        $translations = [
            'documenty' => [
                'metodologiya-monitoringa-2030' => ['«O‘zbekiston–2030» strategiyasini monitoring qilish metodologiyasi', ['category' => 'Metodologiya', 'summary' => 'Islohotlar maqsadlari, indikatorlari va natijalarini baholashga yagona yondashuvlar.']],
                'otchet-strukturnye-reformy' => ['Tarkibiy islohotlarning borishi bo‘yicha tahliliy hisobot', ['category' => 'Tahliliy hisobotlar', 'summary' => 'Tarkibiy o‘zgarishlar monitoringi natijalari va keyingi qadamlar bo‘yicha tavsiyalar.']],
                'reglament-koordinacii' => ['Idoralararo muvofiqlashtirish reglamenti', ['category' => 'Reglamentlar', 'summary' => 'Ma’lumot almashish, qarorlarni kelishish va ijroni nazorat qilish tartibi.']],
                'obzor-mezhdunarodnogo-opyta' => ['Strategik rejalashtirish bo‘yicha xalqaro tajriba sharhi', ['category' => 'Sharhlar', 'summary' => 'Strategik boshqaruv va islohotlarni baholashning zamonaviy modellarini taqqoslash.']],
            ],
            'vakansii' => [
                'vedushchiy-it' => ['IT bo‘limining yetakchi mutaxassisi', ['department' => 'Axborot texnologiyalari bo‘limi', 'requirements' => 'Oliy ma’lumot, kamida 3 yillik tajriba, PHP/MySQL bilimlari.', 'duties' => 'Axborot tizimlarini qo‘llab-quvvatlash va rivojlantirish.']],
                'yuriskonsult' => ['Yuriskonsult', ['department' => 'Yuridik bo‘lim', 'requirements' => 'Oliy yuridik ma’lumot va kamida 2 yillik tajriba.', 'duties' => 'Agentlik faoliyatini huquqiy qo‘llab-quvvatlash.']],
                'specialist-kadry' => ['Kadrlar bo‘yicha mutaxassis', ['department' => 'Inson resurslari bo‘limi', 'requirements' => 'Kadrlar ish yurituvi bo‘yicha tajriba.', 'duties' => 'Kadrlar hisobi va hujjatlarini yuritish.']],
                'press-sekretar' => ['Matbuot kotibi', ['department' => 'Matbuot xizmati', 'requirements' => 'OAV yoki PR sohasida tajriba, savodli nutq.', 'duties' => 'OAV bilan hamkorlik va sayt yangiliklarini yuritish.']],
            ],
            'tendery' => [
                'platforma-monitoringa-zakupka' => ['Monitoring tahliliy platformasini rivojlantirish', ['summary' => 'Ko‘rsatkichlarni vizuallashtirish va idoralararo ma’lumot almashish modullarini ishlab chiqish.']],
                'issledovanie-regionov' => ['Hududlarning ijtimoiy-iqtisodiy dinamikasini o‘rganish', ['summary' => 'Hududlarda o‘sish omillari va hayot sifatini kompleks o‘rganish.']],
                'ekspertnyy-forum' => ['Xalqaro ekspert forumini tashkil etish', ['summary' => 'Ekspert forumini tashkiliy va texnik jihatdan qo‘llab-quvvatlash.']],
            ],
            'meropriyatiya' => [
                'prezentaciya-monitoringa-strategii' => ['Strategiyani monitoring qilish tizimining ochiq taqdimoti', ['location' => 'Toshkent, Agentlik konferensiya zali', 'summary' => '«O‘zbekiston–2030» strategiyasi maqsad va ko‘rsatkichlarini monitoring qilish raqamli tizimi taqdimoti.']],
                'dialog-regionalnoe-razvitie' => ['Hududiy rivojlanish bo‘yicha ekspert muloqoti', ['location' => 'Gibrid shakl', 'summary' => 'Hududlarni rivojlantirish va davlat dasturlari sifatini baholashning yangi yondashuvlari muhokamasi.']],
                'forum-strategicheskih-iniciativ' => ['Strategik tashabbuslar forumi', ['location' => 'Toshkent', 'summary' => 'Davlat organlari, ekspertlar va xalqaro hamkorlar o‘rtasida tajriba almashish maydoni.']],
            ],
        ];
        foreach ($byType as $slug => $rows) {
            $tid = self::typeId($pdo, $slug);
            if ($tid === null) {
                continue;
            }
            foreach ($rows as $r) {
                $ins->execute([':tid' => $tid, ':t' => $r[0], ':s' => $r[1], ':d' => json_encode($r[2], JSON_UNESCAPED_UNICODE), ':tid2' => $tid, ':s2' => $r[1]]);
                $c[$slug] += $ins->rowCount();

                if (self::tableExists($pdo, 'content_entry_translations')) {
                    $entryStmt = $pdo->prepare(
                        'SELECT id FROM content_entries WHERE type_id = :type_id AND slug = :slug LIMIT 1'
                    );
                    $entryStmt->execute([':type_id' => $tid, ':slug' => $r[1]]);
                    $entryId = $entryStmt->fetchColumn();
                    $translation = $translations[$slug][$r[1]] ?? null;
                    if ($entryId !== false && is_array($translation)) {
                        $translatedData = array_replace($r[2], $translation[1]);
                        $transIns = $pdo->prepare(
                            "INSERT INTO content_entry_translations (entry_id, lang, title, data)
                             SELECT :entry_id, 'uz', :title, :data
                             FROM DUAL
                             WHERE NOT EXISTS (
                                 SELECT 1 FROM content_entry_translations
                                 WHERE entry_id = :entry_id2 AND lang = 'uz'
                             )"
                        );
                        $transIns->execute([
                            ':entry_id' => (int) $entryId,
                            ':title' => (string) $translation[0],
                            ':data' => json_encode($translatedData, JSON_UNESCAPED_UNICODE),
                            ':entry_id2' => (int) $entryId,
                        ]);
                    }
                }
            }
        }
    }

    private static function seedProjects(PDO $pdo, array &$c): void
    {
        if (!self::tableExists($pdo, 'pages')) {
            return;
        }

        $projects = [
            ['cifrovaya-transformaciya', 'Цифровая трансформация и развитие инноваций', '/uploads/public/hero-demo-g3.jpg', '<h2>О проекте</h2><p>Проект объединяет цифровизацию государственных услуг, развитие инфраструктуры данных и внедрение современных инструментов мониторинга реформ.</p><h3>Ключевые результаты</h3><ul><li>единая система показателей реализации стратегии;</li><li>межведомственный обмен данными;</li><li>сокращение сроков предоставления государственных услуг.</li></ul><blockquote><p>Цифровые решения должны давать измеримый результат для граждан и бизнеса.</p></blockquote>'],
            ['transportnaya-infrastruktura', 'Развитие транспортной и логистической инфраструктуры', '/uploads/public/hero-demo-g2.jpg', '<h2>О проекте</h2><p>Комплексная программа развития транспортных коридоров, городской мобильности и современной логистической инфраструктуры регионов.</p><h3>План реализации</h3><ol><li>анализ транспортных потоков;</li><li>подготовка приоритетных проектов;</li><li>поэтапная реализация и публичный мониторинг.</li></ol>'],
            ['zelenaya-energetika', 'Увеличение доли возобновляемых источников энергии', '/uploads/public/hero-demo-g4.jpg', '<h2>О проекте</h2><p>Инициатива направлена на повышение энергоэффективности, развитие солнечной и ветровой генерации и рациональное использование природных ресурсов.</p><table><thead><tr><th>Направление</th><th>Цель</th></tr></thead><tbody><tr><td>Возобновляемая энергетика</td><td>Новые генерирующие мощности</td></tr><tr><td>Энергоэффективность</td><td>Снижение удельного потребления</td></tr></tbody></table>'],
            ['investicii-prioritetnye-otrasli', 'Привлечение инвестиций в приоритетные отрасли', '/uploads/public/hero-demo.jpg', '<h2>О проекте</h2><p>Формирование прозрачного портфеля инвестиционных предложений и сопровождение проектов, создающих рабочие места и устойчивую добавленную стоимость.</p><h3>Приоритеты</h3><ul><li>промышленная кооперация;</li><li>региональные инвестиционные программы;</li><li>международное партнёрство.</li></ul>'],
        ];
        $projectImages = [
            'cifrovaya-transformaciya' => '/uploads/public/demo-agency-hero.jpg',
            'transportnaya-infrastruktura' => '/uploads/public/demo-urban-development.jpg',
            'zelenaya-energetika' => '/uploads/public/demo-green-energy.jpg',
            'investicii-prioritetnye-otrasli' => '/uploads/public/demo-strategy-meeting.jpg',
        ];
        // Проект — страница с подтипом: сама запись идёт в pages, тело проекта
        // становится текстовым блоком, анонс для карточки — в lead.
        $ins = $pdo->prepare(
            "INSERT INTO pages (title, slug, entity_type, `lead`, cover_image, status, is_featured, sort_order, layout_type, lang, created_at)
             SELECT :t, :s, 'project', :d, :i, 'published', 1, :o, 'no_sidebar', 'ru', NOW()
             FROM DUAL WHERE NOT EXISTS (
                 SELECT 1 FROM pages chk WHERE chk.slug = :s2 AND chk.entity_type = 'project'
             )"
        );
        $galleryByProject = [];
        foreach ($projects as $i => $project) {
            $ins->execute([
                ':t' => $project[1],
                ':s' => $project[0],
                ':d' => self::projectLead($project[3]),
                ':i' => $projectImages[$project[0]],
                ':o' => $i,
                ':s2' => $project[0],
            ]);
            $c['projects'] += $ins->rowCount();

            $pidStmt = $pdo->prepare("SELECT id FROM pages WHERE entity_type = 'project' AND slug = :s LIMIT 1");
            $pidStmt->execute([':s' => $project[0]]);
            $projectId = $pidStmt->fetchColumn();
            if ($projectId === false) {
                continue;
            }

            $pdo->prepare(
                'UPDATE pages SET translation_group_id = id
                 WHERE id = :id AND (translation_group_id IS NULL OR translation_group_id = 0)'
            )->execute([':id' => (int) $projectId]);
            self::seedProjectBody($pdo, (int) $projectId, 'ru', $project[3]);

            // Фотографии и характеристики проекта — блоки его же страницы:
            // отдельных разделов формы под них больше нет.
            $gallery = array_values(array_unique([
                $projectImages[$project[0]],
                '/uploads/public/demo-strategy-meeting.jpg',
                '/uploads/public/demo-urban-development.jpg',
            ]));
            $galleryItems = [];
            foreach ($gallery as $path) {
                $galleryItems[] = [
                    'kind' => 'photo',
                    'title' => $project[1],
                    'image' => $path,
                    'url' => '',
                    'meta' => '',
                    'text' => '',
                ];
            }
            $galleryByProject[$project[0]] = $galleryItems;
            self::seedProjectBlock($pdo, (int) $projectId, 'ru', 'media_gallery', 'Фотографии проекта', [
                'title' => 'Фотографии проекта',
                'source' => 'manual',
                'items' => $galleryItems,
            ]);

            self::seedProjectBlock($pdo, (int) $projectId, 'ru', 'icon_text', 'Характеристики проекта', [
                'variant' => 'plain',
                'title' => 'Характеристики проекта',
                'columns' => 2,
                'items' => [[
                    'icon_svg' => '',
                    'icon_color' => '',
                    'rows' => "Статус | В реализации\nПериод | 2026–2030\nУровень | "
                        . ($i % 2 === 0 ? 'Национальный' : 'Межрегиональный')
                        . "\nКлючевой результат | Измеримый вклад в достижение целей Стратегии «Узбекистан–2030»",
                ]],
            ]);
        }

        // Узбекская версия проекта — отдельная запись своего языка, связанная
        // через translation_group_id: ровно так её и заводит редактор кнопкой
        // «Создать перевод», и только так её видно в разделе «Проекты».
        $uzProjects = [
            'cifrovaya-transformaciya' => ['Raqamli transformatsiya va innovatsiyalarni rivojlantirish', '<h2>Loyiha haqida</h2><p>Loyiha davlat xizmatlarini raqamlashtirish va ma’lumotlar infratuzilmasini rivojlantirishni birlashtiradi.</p>'],
            'transportnaya-infrastruktura' => ['Transport va logistika infratuzilmasini rivojlantirish', '<h2>Loyiha haqida</h2><p>Transport yo‘laklari va shahar mobilligini rivojlantirish bo‘yicha majmuaviy dastur.</p>'],
            'zelenaya-energetika' => ['Qayta tiklanuvchi energiya manbalari ulushini oshirish', '<h2>Loyiha haqida</h2><p>Quyosh va shamol energiyasini rivojlantirish hamda energiya samaradorligini oshirish tashabbusi.</p>'],
            'investicii-prioritetnye-otrasli' => ['Ustuvor tarmoqlarga investitsiyalarni jalb qilish', '<h2>Loyiha haqida</h2><p>Shaffof investitsiya takliflari portfelini shakllantirish va loyihalarni kuzatib borish.</p>'],
        ];
        $uzIns = $pdo->prepare(
            "INSERT INTO pages (title, slug, entity_type, `lead`, cover_image, status, is_featured, sort_order, layout_type, lang, translation_group_id, created_at)
             SELECT :t, base.slug, 'project', :d, base.cover_image, 'published', base.is_featured, base.sort_order, 'no_sidebar', 'uz',
                    COALESCE(NULLIF(base.translation_group_id, 0), base.id), NOW()
             FROM pages base
             WHERE base.id = :base_id
               AND NOT EXISTS (
                   SELECT 1 FROM pages chk WHERE chk.entity_type = 'project' AND chk.lang = 'uz' AND chk.slug = base.slug
               )"
        );
        foreach ($uzProjects as $slug => $data) {
            $baseStmt = $pdo->prepare("SELECT id FROM pages WHERE entity_type = 'project' AND lang = 'ru' AND slug = :s LIMIT 1");
            $baseStmt->execute([':s' => $slug]);
            $baseId = $baseStmt->fetchColumn();
            if ($baseId === false) {
                continue;
            }
            $uzIns->execute([':t' => $data[0], ':d' => self::projectLead($data[1]), ':base_id' => (int) $baseId]);

            $uzStmt = $pdo->prepare("SELECT id FROM pages WHERE entity_type = 'project' AND lang = 'uz' AND slug = :s LIMIT 1");
            $uzStmt->execute([':s' => $slug]);
            $uzId = $uzStmt->fetchColumn();
            if ($uzId !== false) {
                self::seedProjectBody($pdo, (int) $uzId, 'uz', $data[1]);
                // У узбекской записи свой стек блоков: собираем его так же
                // полно, как русский, иначе UZ-версия выглядит обрезанной.
                self::seedProjectBlock($pdo, (int) $uzId, 'uz', 'media_gallery', 'Loyiha suratlari', [
                    'title' => 'Loyiha suratlari',
                    'source' => 'manual',
                    'items' => $galleryByProject[$slug] ?? [],
                ]);
                self::seedProjectBlock($pdo, (int) $uzId, 'uz', 'icon_text', 'Loyiha ko‘rsatkichlari', [
                    'variant' => 'plain',
                    'title' => 'Loyiha ko‘rsatkichlari',
                    'columns' => 2,
                    'items' => [[
                        'icon_svg' => '',
                        'icon_color' => '',
                        'rows' => "Holati | Amalga oshirilmoqda\nDavri | 2026–2030\nDaraja | Milliy"
                            . "\nAsosiy natija | «O‘zbekiston–2030» strategiyasi maqsadlariga o‘lchanadigan hissa",
                    ]],
                ]);
            }
        }
    }

    /** Короткий анонс проекта для карточки: разметка снимается, текст режется. */
    private static function projectLead(string $html): string
    {
        // Теги заменяем пробелом, а не вырезаем: иначе «</h2><p>» склеивает
        // заголовок со следующим предложением в одно слово.
        $text = strip_tags((string) preg_replace('/<[^>]*>/', ' ', $html));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, 300);
    }

    /**
     * Дополнительный блок демо-проекта (галерея, характеристики). Повторный
     * запуск ничего не дублирует — блок ставится по внутреннему названию.
     *
     * @param array<string, mixed> $data
     */
    private static function seedProjectBlock(
        PDO $pdo,
        int $pageId,
        string $lang,
        string $type,
        string $title,
        array $data
    ): void {
        if (!self::tableExists($pdo, 'blocks')) {
            return;
        }

        $exists = $pdo->prepare('SELECT 1 FROM blocks WHERE page_id = :id AND title = :title LIMIT 1');
        $exists->execute([':id' => $pageId, ':title' => $title]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM blocks WHERE page_id = ' . $pageId)
            ->fetchColumn();
        // Появление секции — по тем же правилам ритма, что у демо-страниц.
        // Первым у проекта идёт текстовый блок описания (sort_order 0), он и
        // есть первый экран, поэтому здесь всегда не первый блок.
        if (!array_key_exists('_reveal', $data)) {
            $look = \App\Core\PagePresets::rhythmFor(['text', $type])[1] ?? [];
            if (isset($look['_reveal'])) {
                $data['_reveal'] = $look['_reveal'];
            }
        }
        $pdo->prepare(
            "INSERT INTO blocks (page_id, lang, type, title, data, custom_css, sort_order, is_active, created_at)
             VALUES (:id, :lang, :type, :title, :data, '', :sort, 1, NOW())"
        )->execute([
            ':id' => $pageId,
            ':lang' => $lang,
            ':type' => $type,
            ':title' => $title,
            ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':sort' => $sort,
        ]);
    }

    /**
     * Тело демо-проекта: один текстовый блок нужного языка. Повторный запуск
     * посева ничего не дублирует — блок ставится только если стек пуст.
     */
    private static function seedProjectBody(PDO $pdo, int $pageId, string $lang, string $html): void
    {
        if (!self::tableExists($pdo, 'blocks') || trim($html) === '') {
            return;
        }

        $exists = $pdo->prepare('SELECT 1 FROM blocks WHERE page_id = :id AND lang = :lang LIMIT 1');
        $exists->execute([':id' => $pageId, ':lang' => $lang]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $pdo->prepare(
            "INSERT INTO blocks (page_id, lang, type, data, custom_css, sort_order, is_active, created_at)
             VALUES (:id, :lang, 'text', :data, '', 0, 1, NOW())"
        )->execute([
            ':id' => $pageId,
            ':lang' => $lang,
            ':data' => json_encode(['variant' => 'default', 'content' => $html], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private static function seedMedia(PDO $pdo, array &$c): void
    {
        if (self::tableExists($pdo, 'photo_albums')) {
            $albums = [
                ['strategiya-2030-v-deystvii', 'Стратегия «Узбекистан–2030» в действии', 'Рабочие заседания, презентации и обсуждение приоритетных реформ.', '/uploads/public/demo-strategy-meeting.jpg', '«O‘zbekiston–2030» strategiyasi amalda', 'Ishchi yig‘ilishlar, taqdimotlar va ustuvor islohotlar muhokamasi.'],
                ['regionalnoe-razvitie', 'Развитие регионов Узбекистана', 'Проекты инфраструктуры и новые точки экономического роста.', '/uploads/public/demo-urban-development.jpg', 'O‘zbekiston hududlarini rivojlantirish', 'Infratuzilma loyihalari va iqtisodiy o‘sishning yangi nuqtalari.'],
                ['zelenaya-transformaciya', 'Зелёная трансформация', 'Энергетика, устойчивые города и экологические инициативы.', '/uploads/public/demo-green-energy.jpg', 'Yashil transformatsiya', 'Energetika, barqaror shaharlar va ekologik tashabbuslar.'],
            ];
            $albumIns = $pdo->prepare(
                "INSERT INTO photo_albums (title, slug, description, cover_url, is_published, is_featured, created_at)
                 SELECT :t, :s, :d, :c, 1, 1, NOW()
                 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM photo_albums WHERE slug = :s2)"
            );
            foreach ($albums as $album) {
                $albumIns->execute([':t' => $album[1], ':s' => $album[0], ':d' => $album[2], ':c' => $album[3], ':s2' => $album[0]]);
                $c['albums'] += $albumIns->rowCount();
                if (self::tableExists($pdo, 'photo_album_images')) {
                    $albumIdStmt = $pdo->prepare('SELECT id FROM photo_albums WHERE slug = :slug LIMIT 1');
                    $albumIdStmt->execute([':slug' => $album[0]]);
                    $albumId = $albumIdStmt->fetchColumn();
                    if ($albumId !== false) {
                        // Автор снимка — отдельным полем (колонка из миграции
                        // подписей): в альбоме он выводится под фото рядом с
                        // подписью, одним компонентом с новостной галереей.
                        $withCredit = self::columnExists($pdo, 'photo_album_images', 'credit');
                        $imageIns = $pdo->prepare(
                            $withCredit
                                ? 'INSERT INTO photo_album_images (album_id, image_url, caption, credit, sort_order, created_at)
                                   SELECT :aid, :url, :caption, :credit, :ord, NOW()
                                   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM photo_album_images WHERE album_id = :aid2 AND image_url = :url2)'
                                : 'INSERT INTO photo_album_images (album_id, image_url, caption, sort_order, created_at)
                                   SELECT :aid, :url, :caption, :ord, NOW()
                                   FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM photo_album_images WHERE album_id = :aid2 AND image_url = :url2)'
                        );
                        foreach (['/uploads/public/demo-strategy-meeting.jpg', '/uploads/public/demo-urban-development.jpg', '/uploads/public/demo-agency-hero.jpg', '/uploads/public/demo-green-energy.jpg'] as $order => $url) {
                            $params = [':aid' => (int) $albumId, ':url' => $url, ':caption' => $album[1], ':ord' => $order, ':aid2' => (int) $albumId, ':url2' => $url];
                            if ($withCredit) {
                                $params[':credit'] = 'пресс-служба Агентства';
                            }
                            $imageIns->execute($params);
                        }

                        if (self::tableExists($pdo, 'photo_album_translations')) {
                            $translationIns = $pdo->prepare(
                                "INSERT INTO photo_album_translations (album_id, lang, title, description)
                                 SELECT :album_id, 'uz', :title, :description
                                 FROM DUAL
                                 WHERE NOT EXISTS (
                                     SELECT 1 FROM photo_album_translations
                                     WHERE album_id = :album_id2 AND lang = 'uz'
                                 )"
                            );
                            $translationIns->execute([
                                ':album_id' => (int) $albumId,
                                ':title' => $album[4],
                                ':description' => $album[5],
                                ':album_id2' => (int) $albumId,
                            ]);
                        }
                    }
                }
            }
        }

        if (self::tableExists($pdo, 'videos')) {
            $videos = [
                ['uzbekistan-2030-klyuchevye-celi', 'Узбекистан–2030: ключевые цели и приоритеты', '/uploads/public/demo-strategy-meeting.jpg', '02:35', 'O‘zbekiston–2030: asosiy maqsad va ustuvor yo‘nalishlar'],
                ['zelenaya-ekonomika', 'Переход к «зелёной» экономике', '/uploads/public/demo-green-energy.jpg', '03:12', 'Yashil iqtisodiyotga o‘tish'],
                ['cifrovye-gosuslugi', 'Цифровая трансформация государственных услуг', '/uploads/public/demo-agency-hero.jpg', '02:08', 'Davlat xizmatlarining raqamli transformatsiyasi'],
            ];
            $videoIns = $pdo->prepare(
                "INSERT INTO videos (title, slug, description, cover_url, video_url, duration, is_published, is_featured, sort_order, created_at)
                 SELECT :t, :s, :d, :c, '/press-centr', :du, 1, 1, :o, NOW()
                 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM videos WHERE slug = :s2)"
            );
            foreach ($videos as $i => $video) {
                $videoIns->execute([':t' => $video[1], ':s' => $video[0], ':d' => 'Информационный видеоматериал Агентства.', ':c' => $video[2], ':du' => $video[3], ':o' => $i, ':s2' => $video[0]]);
                $c['videos'] += $videoIns->rowCount();

                if (self::tableExists($pdo, 'video_translations')) {
                    $videoIdStmt = $pdo->prepare('SELECT id FROM videos WHERE slug = :slug LIMIT 1');
                    $videoIdStmt->execute([':slug' => $video[0]]);
                    $videoId = $videoIdStmt->fetchColumn();
                    if ($videoId !== false) {
                        $videoTranslationIns = $pdo->prepare(
                            "INSERT INTO video_translations (video_id, lang, title, description)
                             SELECT :video_id, 'uz', :title, :description
                             FROM DUAL
                             WHERE NOT EXISTS (
                                 SELECT 1 FROM video_translations
                                 WHERE video_id = :video_id2 AND lang = 'uz'
                             )"
                        );
                        $videoTranslationIns->execute([
                            ':video_id' => (int) $videoId,
                            ':title' => $video[4],
                            ':description' => 'Agentlikning axborot videomateriali.',
                            ':video_id2' => (int) $videoId,
                        ]);
                    }
                }
            }
        }
    }

    private static function seedForms(PDO $pdo, array &$c): void
    {
        if (!self::tableExists($pdo, 'forms')) {
            return;
        }
        $forms = [
            [
                'Обращение в Агентство',
                'public-appeal',
                [
                    ['name' => 'name', 'label' => 'Ваше имя', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
                    ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'required' => false],
                    ['name' => 'topic', 'label' => 'Тема обращения', 'type' => 'select', 'options' => 'Общий вопрос,Предложение,Запрос информации,Запись на приём', 'required' => true],
                    ['name' => 'message', 'label' => 'Сообщение', 'type' => 'textarea', 'required' => true],
                    ['name' => 'consent', 'label' => 'Согласен на обработку предоставленных данных', 'type' => 'checkbox', 'required' => true],
                ],
                'Спасибо! Ваше обращение зарегистрировано.',
            ],
            [
                'Регистрация на мероприятие',
                'event-registration',
                [
                    ['name' => 'name', 'label' => 'Ф.И.О.', 'type' => 'text', 'required' => true],
                    ['name' => 'organization', 'label' => 'Организация', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
                    ['name' => 'participation', 'label' => 'Формат участия', 'type' => 'radio', 'options' => 'Очно,Онлайн', 'required' => true],
                    ['name' => 'topics', 'label' => 'Интересующие направления', 'type' => 'checkbox_group', 'options' => 'Стратегическое планирование,Региональное развитие,Зелёная экономика,Цифровизация', 'required' => false],
                    ['name' => 'event_date', 'label' => 'Предпочтительная дата', 'type' => 'date', 'required' => false],
                ],
                'Регистрация принята. Подтверждение будет направлено на указанный e-mail.',
            ],
            [
                'Заявка в экспертный резерв',
                'expert-pool',
                [
                    ['name' => 'name', 'label' => 'Ф.И.О.', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true],
                    ['name' => 'specialization', 'label' => 'Специализация', 'type' => 'select', 'options' => 'Экономика,Аналитика данных,Управление проектами,Международное сотрудничество', 'required' => true],
                    ['name' => 'experience', 'label' => 'Кратко опишите опыт', 'type' => 'textarea', 'required' => true],
                    ['name' => 'resume', 'label' => 'Резюме', 'type' => 'file', 'required' => true],
                    ['name' => 'consent', 'label' => 'Подтверждаю достоверность сведений', 'type' => 'checkbox', 'required' => true],
                ],
                'Заявка принята и направлена на рассмотрение.',
            ],
        ];
        $ins = $pdo->prepare(
            "INSERT INTO forms (name, slug, fields_json, success_message, created_at)
             SELECT :n, :s, :f, :m, NOW()
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM forms WHERE slug = :s2)"
        );
        foreach ($forms as $form) {
            $ins->execute([
                ':n' => $form[0],
                ':s' => $form[1],
                ':f' => json_encode($form[2], JSON_UNESCAPED_UNICODE),
                ':m' => $form[3],
                ':s2' => $form[1],
            ]);
            $c['forms'] += $ins->rowCount();
        }
    }

    private static function seedTeam(PDO $pdo, array &$c): void
    {
        if (!self::tableExists($pdo, 'team_members')) {
            return;
        }
        // [ФИО, должность, ФИО (uz), должность (uz), сектор, отдел/группа,
        //  сектор (uz), отдел/группа (uz)] — руководство идёт без сектора.
        $team = [
            // Руководство — реальное (см. database/content/agency_content.php),
            // остальные сотрудники демонстрационные.
            ['Умурзаков Сардор Уктамович', 'Директор', 'Umurzoqov Sardor O‘ktamovich', 'Direktor', '', '', '', ''],
            ['Абдукодиров Абдулла Мамасаатович', 'Первый заместитель директора', 'Abduqodirov Abdulla Mamasaatovich', 'Direktor birinchi o‘rinbosari', '', '', '', ''],
            [
                'Каримов Бехзод Шухратович', 'Руководитель сектора',
                'Karimov Behzod Shuhratovich', 'Shoʻba rahbari',
                'Сектор анализа и исследований', '',
                'Tahlil va tadqiqotlar shoʻbasi', '',
            ],
            [
                'Исмоилова Дилноза Фарходовна', 'Руководитель сектора',
                'Ismoilova Dilnoza Farhodovna', 'Shoʻba rahbari',
                'Сектор по связям с общественностью', '',
                'Jamoatchilik bilan aloqalar shoʻbasi', '',
            ],
            [
                'Ражабов Отабек Улугбекович', 'Главный специалист',
                'Rajabov Otabek Ulugʻbekovich', 'Bosh mutaxassis',
                'Информационно-аналитический и организационный сектор', 'группа по работе с кадрами',
                'Axborot-tahlil va tashkiliy masalalar shoʻbasi', 'kadrlar bilan ishlash guruhi',
            ],
            [
                'Хамидова Севара Рустамовна', 'Ведущий специалист',
                'Hamidova Sevara Rustamovna', 'Yetakchi mutaxassis',
                'Информационно-аналитический и организационный сектор', 'первый отдел',
                'Axborot-tahlil va tashkiliy masalalar shoʻbasi', 'birinchi boʻlim',
            ],
        ];
        $ins = $pdo->prepare(
            "INSERT INTO team_members (name, position, department, unit, status, sort_order, created_at)
             SELECT :n, :p, :d, :u, 'published', :o, NOW()
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM team_members WHERE name = :n2)"
        );
        foreach ($team as $i => $t) {
            $ins->execute([
                ':n' => $t[0],
                ':p' => $t[1],
                ':d' => $t[4] !== '' ? $t[4] : null,
                ':u' => $t[5] !== '' ? $t[5] : null,
                ':o' => $i,
                ':n2' => $t[0],
            ]);
            $c['team'] += $ins->rowCount();

            if (self::tableExists($pdo, 'team_member_translations')) {
                $memberStmt = $pdo->prepare('SELECT id FROM team_members WHERE name = :name LIMIT 1');
                $memberStmt->execute([':name' => $t[0]]);
                $memberId = $memberStmt->fetchColumn();
                if ($memberId !== false) {
                    $translationIns = $pdo->prepare(
                        "INSERT INTO team_member_translations (member_id, lang, name, position, department, unit)
                         SELECT :member_id, 'uz', :name, :position, :department, :unit
                         FROM DUAL
                         WHERE NOT EXISTS (
                             SELECT 1 FROM team_member_translations
                             WHERE member_id = :member_id2 AND lang = 'uz'
                         )"
                    );
                    $translationIns->execute([
                        ':member_id' => (int) $memberId,
                        ':name' => $t[2],
                        ':position' => $t[3],
                        ':department' => $t[6] !== '' ? $t[6] : null,
                        ':unit' => $t[7] !== '' ? $t[7] : null,
                        ':member_id2' => (int) $memberId,
                    ]);
                }
            }
        }
    }

    private static function seedPages(PDO $pdo, array &$c): void
    {
        // Страницы с переводами для 'ru' и 'uz'
        $pages = [
            'o-nas' => [
                'ru' => [
                    'title' => 'Об Агентстве',
                    'blocks' => [
                        ['text', 'Об Агентстве', [
                            'title' => 'Об Агентстве',
                            'content' => '<h2>Правовой статус и полномочия</h2><p>Агентство стратегического развития и реформ при Президенте Республики Узбекистан — уполномоченный государственный орган в сфере стратегического планирования и развития страны.</p><p>Указом Президента Республики Узбекистан от 30 октября 2025 года № УП-201 <a href="https://lex.uz/uz/docs/7806484" target="_blank" rel="noopener">«Об организационных мерах по внедрению системы стратегического планирования и развития»</a> в стране создана единая и комплексная система стратегического планирования и развития.</p><p>В соответствии с данным Указом Агентство:</p><ul><li>является уполномоченным государственным органом по регулированию организации системы стратегического планирования и развития, разработке конкретных механизмов, направленных на её эффективное внедрение, а также подготовке проектов документов стратегического планирования;</li><li>координирует деятельность структурных подразделений министерств и ведомств по стратегическому планированию, а также информационно-аналитических групп Совета Министров Республики Каракалпакстан, хокимиятов областей и города Ташкента.</li></ul><p>Организация работы Агентства строится вокруг трёх направлений, закреплённых в его структуре: стратегическое планирование и развитие отраслей и сфер, стратегическое планирование и развитие регионов, а также изучение передового зарубежного опыта и международное сотрудничество. По каждому направлению действуют профильные секторы и проектные офисы.</p><p>Ознакомиться с распределением задач можно в разделе <a href="/struktura">«Структура»</a>, с составом подразделений — в разделе <a href="/rukovodstvo">«Руководство»</a>.</p><h3>Основные документы, касающиеся деятельности Агентства</h3><ul><li><a href="https://lex.uz/uz/docs/5520880" target="_blank" rel="noopener">«О мерах по созданию Агентства стратегического развития Республики Узбекистан»</a> — Указ Президента Республики Узбекистан от 19 июля 2021 года № УП-6264;</li><li><a href="https://lex.uz/uz/docs/6188707" target="_blank" rel="noopener">«О дополнительных мерах по ускорению стратегических реформ»</a> — Указ Президента Республики Узбекистан от 8 сентября 2022 года № УП-216;</li><li><a href="https://lex.uz/uz/docs/6656978" target="_blank" rel="noopener">«О мерах по дальнейшему совершенствованию деятельности Агентства стратегических реформ при Президенте Республики Узбекистан»</a> — Указ Президента Республики Узбекистан от 8 ноября 2023 года № УП-190;</li><li><a href="https://lex.uz/uz/docs/7806484" target="_blank" rel="noopener">«Об организационных мерах по внедрению системы стратегического планирования и развития»</a> — Указ Президента Республики Узбекистан от 30 октября 2025 года № УП-201.</li></ul><p>Полные тексты документов публикуются в Национальной базе данных законодательства Республики Узбекистан lex.uz.</p>'
                        ]]
                    ]
                ],
                'uz' => [
                    'title' => 'Agentlik haqida',
                    'blocks' => [
                        ['text', 'Agentlik haqida', [
                            'title' => 'Agentlik haqida',
                            'content' => '<h2>Huquqiy maqom va vakolatlar</h2><p>Oʻzbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligi — mamlakatda strategik rejalashtirish va rivojlanish sohasidagi vakolatli davlat organi.</p><p>Oʻzbekiston Respublikasi Prezidentining 2025-yil 30-oktabrdagi PF-201-son <a href="https://lex.uz/uz/docs/7806484" target="_blank" rel="noopener">«Strategik rejalashtirish va rivojlanish tizimini joriy etish boʻyicha tashkiliy chora-tadbirlar toʻgʻrisida»</a>gi Farmoni bilan mamlakatda yagona va kompleks strategik rejalashtirish va rivojlanish tizimi yaratildi.</p><p>Ushbu Farmonga muvofiq Agentlik:</p><ul><li>strategik rejalashtirish va rivojlanish tizimini tashkil etishni tartibga solish, uni samarali joriy etishga qaratilgan aniq mexanizmlarni ishlab chiqish, shuningdek, strategik rejalashtirish hujjatlari loyihalarini tayyorlash boʻyicha vakolatli davlat organi hisoblanadi;</li><li>vazirlik va idoralarning strategik rejalashtirish boʻyicha tarkibiy boʻlinmalari, shuningdek, Qoraqalpogʻiston Respublikasi Vazirlar Kengashi, viloyatlar va Toshkent shahri hokimliklarining axborot-tahlil guruhlari faoliyatini muvofiqlashtiradi.</li></ul><p>Agentlik faoliyati uning tuzilmasida mustahkamlangan uch yoʻnalish atrofida tashkil etilgan: soha va tarmoqlarni strategik rejalashtirish va rivojlantirish, hududlarni strategik rejalashtirish va rivojlantirish, hamda ilgʻor xorijiy tajribani oʻrganish va xalqaro hamkorlik. Har bir yoʻnalish boʻyicha tegishli shoʻbalar va loyiha ofislari faoliyat yuritadi.</p><p>Vazifalar taqsimoti bilan <a href="/struktura">«Tuzilma»</a> boʻlimida, boʻlinmalar tarkibi bilan <a href="/rukovodstvo">«Rahbariyat»</a> boʻlimida tanishishingiz mumkin.</p><h3>Agentlik faoliyatiga oid asosiy hujjatlar</h3><ul><li><a href="https://lex.uz/uz/docs/5520880" target="_blank" rel="noopener">«Oʻzbekiston Respublikasi Strategik taraqqiyot agentligini tashkil etish chora-tadbirlari toʻgʻrisida»</a> — Oʻzbekiston Respublikasi Prezidentining 2021-yil 19-iyuldagi PF-6264-son Farmoni;</li><li><a href="https://lex.uz/uz/docs/6188707" target="_blank" rel="noopener">«Strategik islohotlarni jadallashtirish boʻyicha qoʻshimcha chora-tadbirlar toʻgʻrisida»</a> — Oʻzbekiston Respublikasi Prezidentining 2022-yil 8-sentyabrdagi PF-216-son Farmoni;</li><li><a href="https://lex.uz/uz/docs/6656978" target="_blank" rel="noopener">«Oʻzbekiston Respublikasi Prezidenti huzuridagi Strategik islohotlar agentligi faoliyatini yanada takomillashtirish chora-tadbirlari toʻgʻrisida»</a> — Oʻzbekiston Respublikasi Prezidentining 2023-yil 8-noyabrdagi PF-190-son Farmoni;</li><li><a href="https://lex.uz/uz/docs/7806484" target="_blank" rel="noopener">«Strategik rejalashtirish va rivojlanish tizimini joriy etish boʻyicha tashkiliy chora-tadbirlar toʻgʻrisida»</a> — Oʻzbekiston Respublikasi Prezidentining 2025-yil 30-oktabrdagi PF-201-son Farmoni.</li></ul><p>Hujjatlarning toʻliq matnlari Oʻzbekiston Respublikasi qonunchilik maʼlumotlari milliy bazasi lex.uz saytida eʼlon qilinadi.</p>'
                        ]]
                    ]
                ]
            ],
            'rukovodstvo' => [
                'ru' => [
                    'title' => 'Руководство',
                    'blocks' => [
                        ['text', 'Введение', ['title' => 'Руководство', 'content' => '<p>Руководящий состав организации.</p>']],
                        ['team_list', 'Команда', ['title' => 'Руководящий состав', 'limit' => 0, 'group_by_department' => true]],
                        ['cta', 'Директор', ['variant' => 'band', 'title' => 'Директор Агентства', 'text' => 'Биография, приоритеты работы и публикации руководителя.', 'button_text' => 'Страница директора', 'button_url' => '/direktor', 'bg_color' => '#072b61', 'text_color' => '#ffffff']]
                    ]
                ],
                'uz' => [
                    'title' => 'Rahbariyat',
                    'blocks' => [
                        ['text', 'Kirish', ['title' => 'Rahbariyat', 'content' => '<p>Tashkilotning rahbariyat tarkibi.</p>']],
                        ['team_list', 'Jamoa', ['title' => 'Rahbariyat tarkibi', 'limit' => 0, 'group_by_department' => true]]
                    ]
                ]
            ],
            'struktura' => [
                'ru' => [
                    'title' => 'Структура',
                    'lead' => 'Руководство, секторы и проектные офисы Агентства, а также их подчинённость.',
                    'blocks' => [
                        ['org_structure', 'Оргсхема', [
                            'title' => 'Структура Агентства стратегического развития и реформ при Президенте Республики Узбекистан',
                            'layout' => 'tree',
                            'columns' => 4,
                            'council' => 'Координационный совет',
                            'head_title' => 'Директор',
                            'head_name' => '',
                            'head_url' => '/direktor',
                            'side_items' => 'Советник',
                            'branches' => [
                                ['title' => 'Первый заместитель директора', 'name' => '', 'units' => "Сектор стратегического планирования и развития отраслей и сфер\nСектор анализа и исследований | /rukovodstvo#team-sektor-analiza-i-issledovaniy\nСектор организации деятельности Координационного совета\n* Проектные офисы по развитию отраслей"],
                                ['title' => 'Заместитель директора', 'name' => '', 'units' => "Сектор стратегического планирования и развития регионов\nСектор контроля за исполнением задач по стратегическому развитию\nСектор экспертизы проектов нормативно-правовых актов\n* Проектные офисы по развитию регионов"],
                                ['title' => 'Заместитель директора', 'name' => '', 'units' => "Сектор изучения передового зарубежного опыта, результатов научно-исследовательской деятельности и практики\nСектор координации процессов привлечения иностранных экспертов, консультантов и советников\n* Проектные офисы по развитию международного сотрудничества"],
                                ['title' => '', 'name' => '', 'units' => "Сектор координации системы стратегического планирования\nСектор мониторинга и оценки эффективности реформ\nИнформационно-аналитический и организационный сектор | /rukovodstvo#team-informacionno-analiticheskiy-i-organizacionnyy-sektor\n- группа по работе с кадрами\n- первый отдел\nФинансово-хозяйственный сектор (Главный бухгалтер)\n- группа материального обеспечения и хозяйственных дел\nСектор по связям с общественностью | /rukovodstvo#team-sektor-po-svyazyam-s-obschestvennostyu"],
                            ],
                            'notes' => 'Секторы четвёртой колонки подчиняются директору напрямую.',
                            'footnote' => 'Структура утверждена в установленном порядке и может уточняться при изменении задач Агентства.',
                        ]]
                    ]
                ],
                'uz' => [
                    'title' => 'Tuzilma',
                    'lead' => 'Agentlik rahbariyati, shoʻbalari va loyiha ofislari hamda ularning boʻysunuvi.',
                    'blocks' => [
                        ['org_structure', 'Tuzilma sxemasi', [
                            'title' => 'Oʻzbekiston Respublikasi Prezidenti huzuridagi Strategik rivojlanish va islohotlar agentligining tuzilmasi',
                            'layout' => 'tree',
                            'columns' => 4,
                            'council' => 'Muvofiqlashtiruvchi kengash',
                            'head_title' => 'Direktor',
                            'head_name' => '',
                            'head_url' => '/direktor',
                            'side_items' => 'Maslahatchi',
                            'branches' => [
                                ['title' => 'Direktorning birinchi oʻrinbosari', 'name' => '', 'units' => "Soha va tarmoqlarni strategik rejalashtirish va rivojlantirish shoʻbasi\nTahlil va tadqiqotlar shoʻbasi\nMuvofiqlashtiruvchi kengash faoliyatini tashkil qilish shoʻbasi\n* Tarmoqlarni rivojlantirish boʻyicha loyiha ofislari"],
                                ['title' => 'Direktor oʻrinbosari', 'name' => '', 'units' => "Hududlarni strategik rejalashtirish va rivojlantirish shoʻbasi\nStrategik rivojlanish boʻyicha vazifalar ijrosini nazorat qilish shoʻbasi\nMeʼyoriy-huquqiy hujjatlar loyihalarini ekspertiza qilish shoʻbasi\n* Hududlarni rivojlantirish boʻyicha loyiha ofislari"],
                                ['title' => 'Direktor oʻrinbosari', 'name' => '', 'units' => "Ilgʻor xorijiy tajriba, ilmiy-tadqiqot va amaliyot natijalarini oʻrganish shoʻbasi\nXorijiy ekspertlar, konsultant va maslahatchilarni jalb etish jarayonlarini muvofiqlashtirish shoʻbasi\n* Xalqaro hamkorlikni rivojlantirish boʻyicha loyiha ofislari"],
                                ['title' => '', 'name' => '', 'units' => "Strategik rejalashtirish tizimini muvofiqlashtirish shoʻbasi\nIslohotlar samaradorligini monitoring qilish va baholash shoʻbasi\nAxborot-tahlil va tashkiliy masalalar shoʻbasi\n- kadrlar bilan ishlash guruhi\n- birinchi boʻlim\nMoliya-xoʻjalik shoʻbasi (Bosh buxgalter)\n- moddiy taʼminot va xoʻjalik ishlari guruhi\nJamoatchilik bilan aloqalar shoʻbasi"],
                            ],
                            'notes' => 'Toʻrtinchi ustundagi shoʻbalar bevosita direktorga boʻysunadi.',
                            'footnote' => 'Tuzilma belgilangan tartibda tasdiqlangan boʻlib, Agentlik vazifalari oʻzgarganda aniqlashtirilishi mumkin.',
                        ]]
                    ]
                ]
            ],
            'antikorrupciya' => [
                'ru' => [
                    'title' => 'Противодействие коррупции',
                    'lead' => 'Антикоррупционная политика Агентства, нормативные документы и порядок обращений.',
                    'blocks' => [
                        ['text', 'Антикоррупция', ['title' => 'Противодействие коррупции', 'content' => '<p>Организация проводит последовательную антикоррупционную политику. Ознакомиться с нормативными документами можно в разделе «Документы».</p><p>Сообщить о фактах коррупции можно через форму обратной связи.</p>']]
                    ]
                ],
                'uz' => [
                    'title' => 'Korrupsiyaga qarshi kurash',
                    'lead' => 'Agentlikning korrupsiyaga qarshi siyosati, normativ hujjatlar va murojaat tartibi.',
                    'blocks' => [
                        ['text', 'Korrupsiyaga qarshi kurashish', ['title' => 'Korrupsiyaga qarshi kurashish', 'content' => '<p>Tashkilotda korrupsiyaga qarshi kurashish bo‘yicha tizimli siyosat yuritiladi. Normativ hujjatlar bilan «Hujjatlar» bo‘limida tanishishingiz mumkin.</p><p>Korrupsiya holatlari haqida xabar berish uchun qayta aloqa shaklidan foydalanishingiz mumkin.</p>']]
                    ]
                ]
            ],
            // Видео и фото живут в блоке «Медиагалерея»; отдельного маршрута
            // /videos у публички нет, поэтому раздел — обычная страница.
            'media' => [
                'ru' => [
                    'title' => 'Медиатека',
                    'lead' => 'Фотографии и видеозаписи с мероприятий, встреч и рабочих поездок Агентства.',
                    'blocks' => [
                        ['media_gallery', 'Медиатека', ['title' => 'Фото и видео', 'source' => 'media', 'limit' => 12]]
                    ]
                ],
                'uz' => [
                    'title' => 'Mediateka',
                    'lead' => 'Agentlik tadbirlari, uchrashuvlari va ish safarlaridan foto va videolavhalar.',
                    'blocks' => [
                        ['media_gallery', 'Mediateka', ['title' => 'Foto va video', 'source' => 'media', 'limit' => 12]]
                    ]
                ]
            ]
        ];

        $prototypeFixture = \dirname(__DIR__, 2) . '/database/demo_assets/prototype_pages.json';
        if (is_file($prototypeFixture)) {
            $prototypePages = json_decode((string) file_get_contents($prototypeFixture), true);
            if (is_array($prototypePages)) {
                $assetAliases = [
                    '/uploads/public/hero-demo.jpg' => '/uploads/public/demo-strategy-meeting.jpg',
                    '/uploads/public/hero-demo-g2.jpg' => '/uploads/public/demo-urban-development.jpg',
                    '/uploads/public/hero-demo-g3.jpg' => '/uploads/public/demo-agency-hero.jpg',
                    '/uploads/public/hero-demo-g4.jpg' => '/uploads/public/demo-green-energy.jpg',
                ];
                array_walk_recursive($prototypePages, static function (&$value) use ($assetAliases): void {
                    if (is_string($value) && isset($assetAliases[$value])) {
                        $value = $assetAliases[$value];
                    }
                });
                $pages = array_replace($pages, $prototypePages);
            }
        }

        // Реальный контент Агентства перекрывает демо-страницы: после установки
        // на сайте не должно оставаться вымышленного руководителя. Тот же
        // источник использует database/seed_agency_content.php, поэтому демо и
        // боевая загрузка не расходятся.
        $agencyFixture = \dirname(__DIR__, 2) . '/database/content/agency_content.php';
        if (is_file($agencyFixture)) {
            $agencyContent = require $agencyFixture;
            if (is_array($agencyContent) && is_array($agencyContent['pages'] ?? null)) {
                $pages = array_replace($pages, $agencyContent['pages']);
            }
        }

        $pageIns = $pdo->prepare(
            "INSERT INTO pages
                (title, slug, meta_title, meta_description, `lead`, status, is_home,
                 layout_type, lang, translation_group_id, created_at)
             SELECT :t, :s, :mt, :md, :lead, 'published', 0,
                    'no_sidebar', :lang, :group_id, NOW()
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM pages WHERE slug = :s2 AND lang = :lang2
             )"
        );

        $transIns = $pdo->prepare(
            "INSERT INTO page_translations (page_id, lang, title, meta_title, meta_description, `lead`)
             SELECT :pid, :lang, :title, :meta_title, :meta_description, :lead
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM page_translations WHERE page_id = :pid2 AND lang = :lang2)"
        );

        $blockIns = $pdo->prepare(
            'INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
             VALUES (:pid, :lang, :ty, :ti, :d, :so, 1, NOW())'
        );

        $activeLangs = self::tableExists($pdo, 'languages')
            ? ($pdo->query('SELECT code FROM languages WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN) ?: ['ru', 'uz'])
            : ['ru', 'uz'];

        $enPageTitles = [
            'o-nas' => 'About Agency',
            'rukovodstvo' => 'Leadership',
            'struktura' => 'Structure',
            'direktor' => 'Director',
            'pervyy-zamestitel-direktora' => 'First Deputy Director',
            'antikorrupciya' => 'Anti-Corruption',
            'napravleniya' => 'Priority Areas',
            'strategiya-2030' => 'Strategy «Uzbekistan–2030»',
            'ustoychivyy-ekonomicheskiy-rost' => 'Sustainable Economic Growth',
            'analitika' => 'Analytics',
            'press-centr' => 'Press Center',
            'meropriyatiya' => 'Events',
            'media' => 'Media Library',
            'kontakty' => 'Contacts',
            'karera' => 'Careers',
        ];

        $createdPageSlugs = [];
        foreach ($pages as $slug => $langData) {
            if (!is_array($langData)) {
                continue;
            }
            $ruData = $langData['ru'] ?? null;
            if (!is_array($ruData)) {
                continue;
            }

            foreach ($activeLangs as $aLang) {
                if ($aLang !== 'ru' && !isset($langData[$aLang])) {
                    $langData[$aLang] = [
                        'title' => ($aLang === 'en' ? ($enPageTitles[$slug] ?? $ruData['title']) : $ruData['title']),
                        'lead' => $aLang === 'en' ? 'Official information and key materials of the Agency.' : ($ruData['lead'] ?? ''),
                        'blocks' => $ruData['blocks'] ?? [],
                    ];
                }
            }

            // Основная версия создаётся первой и становится корнем группы.
            // Благодаря UNIQUE(slug, lang) языковые версии используют один URL,
            // но в редакторе являются самостоятельными страницами.
            $orderedLangData = ['ru' => $ruData] + $langData;
            $groupId = null;
            foreach ($orderedLangData as $lang => $data) {
                if (!is_array($data)) {
                    continue;
                }
                $lang = (string) $lang;
                $title = (string) ($data['title'] ?? '');
                $blocks = $data['blocks'] ?? [];
                $metaTitle = array_key_exists('meta_title', $data)
                    ? (string) $data['meta_title']
                    : $title . ($lang === 'uz'
                        ? ' — Strategik rivojlanish va islohotlar agentligi'
                        : ($lang === 'en'
                            ? ' — Agency for Strategic Development and Reforms'
                            : ' — Агентство стратегического развития и реформ'));
                $metaDescription = array_key_exists('meta_description', $data)
                    ? (string) $data['meta_description']
                    : ($lang === 'uz'
                        ? '«' . $title . '» bo‘limining rasmiy ma’lumotlari.'
                        : ($lang === 'en'
                            ? 'Official information for the «' . $title . '» section.'
                            : 'Официальная информация раздела «' . $title . '».'));
                // Лид из фикстуры может быть пустым намеренно: на странице с
                // блоком «Профиль персоны» заголовок даёт сам блок, и лид
                // добавил бы второй h1.
                $lead = array_key_exists('lead', $data)
                    ? (string) $data['lead']
                    : ($lang === 'uz'
                        ? 'Agentlikning strategik tashabbuslari, natijalari va dolzarb materiallari.'
                        : ($lang === 'en'
                            ? 'Strategic initiatives, results, and key materials of the Agency.'
                            : 'Стратегические инициативы, результаты и актуальные материалы Агентства.'));

                if ($lang !== 'ru' && $groupId === null) {
                    continue;
                }
                $pageIns->execute([
                    ':t' => $title,
                    ':s' => $slug,
                    ':mt' => $metaTitle,
                    ':md' => $metaDescription,
                    ':lead' => $lead,
                    ':lang' => $lang,
                    ':group_id' => $lang === 'ru' ? null : $groupId,
                    ':s2' => $slug,
                    ':lang2' => $lang,
                ]);
                $pageCreated = $pageIns->rowCount();
                $c['pages'] += $pageCreated;
                if ($pageCreated > 0) {
                    $createdPageSlugs[] = (string) $slug;
                }

                $pid = self::pageIdForLang($pdo, (string) $slug, $lang);
                if ($pid === null) {
                    continue;
                }
                if ($lang === 'ru') {
                    $pdo->prepare(
                        'UPDATE pages
                         SET translation_group_id = id
                         WHERE id = :id
                           AND (translation_group_id IS NULL OR translation_group_id = 0)'
                    )->execute([':id' => $pid]);
                    $groupStmt = $pdo->prepare(
                        'SELECT translation_group_id FROM pages WHERE id = :id LIMIT 1'
                    );
                    $groupStmt->execute([':id' => $pid]);
                    $storedGroupId = $groupStmt->fetchColumn();
                    $groupId = $storedGroupId !== false && (int) $storedGroupId > 0
                        ? (int) $storedGroupId
                        : $pid;
                } else {
                    $pdo->prepare(
                        'UPDATE pages
                         SET translation_group_id = :group_id
                         WHERE id = :id
                           AND (translation_group_id IS NULL OR translation_group_id = 0 OR translation_group_id = id)'
                    )->execute([':group_id' => $groupId, ':id' => $pid]);
                }

                // Сохраняем старую таблицу метаданных как совместимый fallback.
                $transIns->execute([
                    ':pid' => $groupId,
                    ':lang' => $lang,
                    ':title' => $title,
                    ':meta_title' => $metaTitle,
                    ':meta_description' => $metaDescription,
                    ':lead' => $lead,
                    ':pid2' => $groupId,
                    ':lang2' => $lang
                ]);

                $hasBlocksStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM blocks WHERE page_id = :page_id'
                );
                $hasBlocksStmt->execute([':page_id' => $pid]);
                $hasBlocks = (int) $hasBlocksStmt->fetchColumn() > 0;
                if (!$hasBlocks && is_array($blocks)) {
                    $order = 1;
                    // Оформление секций (фоны, отступы, появление) — по общим
                    // правилам ритма, чтобы демо-страницы не были плоскими.
                    // Тексты блоков при этом свои: демо-контент конкретного
                    // ведомства ценнее заготовок из готовых сборок.
                    $valid = array_values(array_filter(
                        $blocks,
                        static fn ($b): bool => is_array($b) && count($b) >= 3
                    ));
                    $looks = \App\Core\PagePresets::rhythmFor(
                        array_map(static fn (array $b): string => (string) $b[0], $valid)
                    );
                    $lookIndex = 0;
                    foreach ($blocks as $block) {
                        if (!is_array($block) || count($block) < 3) {
                            continue;
                        }
                        $type = (string) ($block[0] ?? '');
                        $btitle = (string) ($block[1] ?? '');
                        $blockData = $block[2] ?? [];
                        if ($type === 'form' && is_array($blockData) && !empty($blockData['form_slug'])) {
                            $formStmt = $pdo->prepare('SELECT id FROM forms WHERE slug = :slug LIMIT 1');
                            $formStmt->execute([':slug' => (string) $blockData['form_slug']]);
                            $formId = $formStmt->fetchColumn();
                            $blockData['form_id'] = $formId !== false ? (int) $formId : null;
                            unset($blockData['form_slug']);
                        }

                        // Своё оформление блока (если задано в демо-данных)
                        // важнее автоматического — не перетираем.
                        if (is_array($blockData)) {
                            $blockData += $looks[$lookIndex] ?? [];
                        }
                        $lookIndex++;

                        $blockIns->execute([
                            ':pid' => $pid,
                            ':lang' => $lang,
                            ':ty' => $type,
                            ':ti' => $btitle,
                            ':d' => json_encode($blockData, JSON_UNESCAPED_UNICODE),
                            ':so' => $order++
                        ]);
                    }
                }
            }
        }

        // Иерархия влияет на дерево и хлебные крошки, но не меняет URL.
        // При обычном идемпотентном запуске не переназначаем родителя у
        // существующих редакционных страниц — связываем только что созданные.
        $hierarchy = [
            'rukovodstvo' => 'o-nas',
            'struktura' => 'o-nas',
            'direktor' => 'rukovodstvo',
            'antikorrupciya' => 'o-nas',
            'strategiya-2030' => 'napravleniya',
            'ustoychivyy-ekonomicheskiy-rost' => 'napravleniya',
            'analitika' => 'napravleniya',
            'meropriyatiya' => 'press-centr',
        ];
        $setParent = $pdo->prepare(
            'UPDATE pages child
             INNER JOIN pages parent ON parent.slug = :parent_slug AND parent.lang = child.lang
             SET child.parent_id = parent.id
             WHERE child.slug = :child_slug
               AND child.parent_id IS NULL
               AND child.deleted_at IS NULL
               AND parent.deleted_at IS NULL'
        );
        foreach ($hierarchy as $childSlug => $parentSlug) {
            if (!in_array($childSlug, $createdPageSlugs, true)) {
                continue;
            }
            $setParent->execute([
                ':child_slug' => $childSlug,
                ':parent_slug' => $parentSlug,
            ]);
        }
    }

    private static function seedMenu(PDO $pdo, array &$c): void
    {
        if (!self::tableExists($pdo, 'menu_items')) {
            return;
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM menu_items')->fetchColumn() > 0) {
            return;
        }
        /** @var array<string, list<array{title: string, type: string, value: string, mega: int, children: list<array{0: string, 1: string, 2: string, 3?: string}>}>> $menus */
        $menus = [
            'ru' => [
                ['title' => 'Агентство', 'type' => 'page', 'value' => 'o-nas', 'mega' => 0, 'children' => [
                    ['Об агентстве', 'page', 'o-nas'],
                    ['Руководство', 'page', 'rukovodstvo'],
                    ['Структура', 'page', 'struktura'],
                    ['Директор', 'page', 'direktor'],
                    ['Первый заместитель директора', 'page', 'pervyy-zamestitel-direktora'],
                    ['Противодействие коррупции', 'page', 'antikorrupciya'],
                ]],
                ['title' => 'Деятельность', 'type' => 'page', 'value' => 'napravleniya', 'mega' => 0, 'children' => [
                    ['Стратегия «Узбекистан–2030»', 'page', 'strategiya-2030', '2030'],
                    ['Приоритетные направления', 'page', 'napravleniya'],
                    ['Устойчивый экономический рост', 'page', 'ustoychivyy-ekonomicheskiy-rost'],
                    ['Проекты и инициативы', 'custom', '/projects'],
                    ['Аналитика', 'page', 'analitika'],
                ]],
                ['title' => 'Пресс-центр', 'type' => 'page', 'value' => 'press-centr', 'mega' => 0, 'children' => [
                    ['Новости', 'news_index', ''],
                    ['Мероприятия', 'page', 'meropriyatiya'],
                    ['Фотоальбомы', 'custom', '/albums'],
                    ['Видеоматериалы', 'page', 'media'],
                ]],
                ['title' => 'Открытые данные', 'type' => 'custom', 'value' => '/catalog/documenty', 'mega' => 0, 'children' => [
                    ['Документы', 'custom', '/catalog/documenty'],
                    ['Тендеры', 'custom', '/catalog/tendery'],
                    ['Вакансии', 'custom', '/catalog/vakansii'],
                ]],
                ['title' => 'Контакты', 'type' => 'page', 'value' => 'kontakty', 'mega' => 0, 'children' => []],
            ],
            'uz' => [
                ['title' => 'Agentlik', 'type' => 'page', 'value' => 'o-nas', 'mega' => 0, 'children' => [
                    ['Agentlik haqida', 'page', 'o-nas'],
                    ['Rahbariyat', 'page', 'rukovodstvo'],
                    ['Tuzilma', 'page', 'struktura'],
                    ['Direktor', 'page', 'direktor'],
                    ['Direktorning birinchi o‘rinbosari', 'page', 'pervyy-zamestitel-direktora'],
                    ['Korrupsiyaga qarshi kurash', 'page', 'antikorrupciya'],
                ]],
                ['title' => 'Faoliyat', 'type' => 'page', 'value' => 'napravleniya', 'mega' => 0, 'children' => [
                    ['«O‘zbekiston–2030» strategiyasi', 'page', 'strategiya-2030', '2030'],
                    ['Ustuvor yo‘nalishlar', 'page', 'napravleniya'],
                    ['Barqaror iqtisodiy o‘sish', 'page', 'ustoychivyy-ekonomicheskiy-rost'],
                    ['Loyihalar va tashabbuslar', 'custom', '/projects'],
                    ['Tahlil', 'page', 'analitika'],
                ]],
                ['title' => 'Matbuot markazi', 'type' => 'page', 'value' => 'press-centr', 'mega' => 0, 'children' => [
                    ['Yangiliklar', 'news_index', ''],
                    ['Tadbirlar', 'page', 'meropriyatiya'],
                    ['Fotoalbomlar', 'custom', '/albums'],
                    ['Videomateriallar', 'page', 'media'],
                ]],
                ['title' => 'Ochiq ma’lumotlar', 'type' => 'custom', 'value' => '/catalog/documenty', 'mega' => 0, 'children' => [
                    ['Hujjatlar', 'custom', '/catalog/documenty'],
                    ['Tenderlar', 'custom', '/catalog/tendery'],
                    ['Bo‘sh ish o‘rinlari', 'custom', '/catalog/vakansii'],
                ]],
                ['title' => 'Aloqa', 'type' => 'page', 'value' => 'kontakty', 'mega' => 0, 'children' => []],
            ],
            'en' => [
                ['title' => 'Agency', 'type' => 'page', 'value' => 'o-nas', 'mega' => 0, 'children' => [
                    ['About Agency', 'page', 'o-nas'],
                    ['Leadership', 'page', 'rukovodstvo'],
                    ['Structure', 'page', 'struktura'],
                    ['Director', 'page', 'direktor'],
                    ['First Deputy Director', 'page', 'pervyy-zamestitel-direktora'],
                    ['Anti-Corruption', 'page', 'antikorrupciya'],
                ]],
                ['title' => 'Activity', 'type' => 'page', 'value' => 'napravleniya', 'mega' => 0, 'children' => [
                    ['Strategy «Uzbekistan–2030»', 'page', 'strategiya-2030', '2030'],
                    ['Priority Areas', 'page', 'napravleniya'],
                    ['Sustainable Economic Growth', 'page', 'ustoychivyy-ekonomicheskiy-rost'],
                    ['Projects and Initiatives', 'custom', '/projects'],
                    ['Analytics', 'page', 'analitika'],
                ]],
                ['title' => 'Press Center', 'type' => 'page', 'value' => 'press-centr', 'mega' => 0, 'children' => [
                    ['News', 'news_index', ''],
                    ['Events', 'page', 'meropriyatiya'],
                    ['Photo Albums', 'custom', '/albums'],
                    ['Media Library', 'page', 'media'],
                ]],
                ['title' => 'Open Data', 'type' => 'custom', 'value' => '/catalog/documenty', 'mega' => 0, 'children' => [
                    ['Documents', 'custom', '/catalog/documenty'],
                    ['Tenders', 'custom', '/catalog/tendery'],
                    ['Vacancies', 'custom', '/catalog/vakansii'],
                ]],
                ['title' => 'Contacts', 'type' => 'page', 'value' => 'kontakty', 'mega' => 0, 'children' => []],
            ],
        ];
        $langs = $pdo->query('SELECT code FROM languages WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($langs === []) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO menu_items
                (lang, title, badge_text, badge_color, badge_pos, url_type, url_value, parent_id, mega_columns, sort_order, is_active, created_at)
             VALUES
                (:lang, :title, :badge, :badge_color, :badge_pos, :url_type, :url_value, :parent_id, :mega, :sort_order, 1, NOW())'
        );
        foreach ($langs as $lang) {
            $lang = (string) $lang;
            $items = $menus[$lang] ?? $menus['ru'];
            foreach ($items as $i => $item) {
                $ins->execute([
                    ':lang' => $lang,
                    ':title' => $item['title'],
                    ':badge' => null,
                    ':badge_color' => 'blue',
                    ':badge_pos' => 'right',
                    ':url_type' => $item['type'],
                    ':url_value' => $item['value'],
                    ':parent_id' => null,
                    ':mega' => $item['mega'],
                    ':sort_order' => $i,
                ]);
                $c['menu'] += $ins->rowCount();
                $parentId = (int) $pdo->lastInsertId();
                foreach ($item['children'] as $childOrder => $child) {
                    $badge = $child[3] ?? null;
                    $ins->execute([
                        ':lang' => $lang,
                        ':title' => $child[0],
                        ':badge' => $badge,
                        ':badge_color' => $badge !== null ? 'blue' : null,
                        ':badge_pos' => 'right',
                        ':url_type' => $child[1],
                        ':url_value' => $child[2],
                        ':parent_id' => $parentId,
                        ':mega' => 0,
                        ':sort_order' => $childOrder,
                    ]);
                    $c['menu'] += $ins->rowCount();
                }
            }
        }
    }

    private static function typeId(PDO $pdo, string $slug): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM content_types WHERE slug = :s LIMIT 1');
        $stmt->execute([':s' => $slug]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Демо-обложка: отдельный тип контента со своим набором слайдов.
     *
     * Блок «Обложка» на главной перестаёт хранить содержимое и начинает
     * ссылаться на эту запись — свежая установка сразу показывает, как обложка
     * задумана: карусель, кадр-замена у видео и переводы слайдов. Обе языковые
     * версии главной ссылаются на ОДНУ обложку: текст слайда переводится
     * (hero_slide_translations), а медиа и раскладка у него общие.
     */
    private static function seedHeroes(PDO $pdo, array &$c): void
    {
        // Миграция обложек могла быть не накатана — демо-контент от этого
        // падать не должен.
        if (!self::tableExists($pdo, 'heroes') || !self::tableExists($pdo, 'hero_slides')) {
            return;
        }
        // Идемпотентность: повторный запуск ничего не создаёт.
        if ((int) $pdo->query('SELECT COUNT(*) FROM heroes WHERE deleted_at IS NULL')->fetchColumn() > 0) {
            return;
        }

        $settings = \App\Core\Hero\HeroPresets::apply('navy', \App\Core\Hero\HeroSettings::defaults());
        // Пресет Navy жёстко объявляет светлый текст — он и нужен на тёмном
        // фоне. Но в демо есть светлый кадр, и жёсткая настройка оставила бы
        // на нём белый текст: явный выбор всегда главнее автоподбора. На
        // navy-фоне «авто» даёт тот же светлый текст, поэтому остальные слайды
        // не меняются, а светлый получает тёмный.
        $settings['content_scheme'] = 'auto';
        $settings['autoplay'] = true;
        $settings['autoplay_interval'] = 7;
        $settings['height'] = 'tall';

        $insertHero = $pdo->prepare(
            "INSERT INTO heroes (name, status, priority, preset, settings, created_at)
             VALUES (:name, 'published', 10, 'navy', :settings, NOW())"
        );
        $insertHero->execute([
            ':name' => 'Главная — обложка Агентства',
            ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);
        $heroId = (int) $pdo->lastInsertId();
        $c['heroes']++;

        $slides = [
            [
                'ru' => [
                    'eyebrow' => 'Цель. Действие. Результат.',
                    'title' => 'От стратегической цели — к измеримому результату',
                    'subtitle' => 'Агентство формирует единую систему стратегического планирования: от анализа и подготовки инициатив до координации, мониторинга и оценки достигнутых результатов.',
                    'cta_text' => 'Об Агентстве',
                    'cta2_text' => 'Узбекистан — 2030',
                ],
                'uz' => [
                    'eyebrow' => 'Maqsad. Harakat. Natija.',
                    'title' => 'Strategik maqsaddan — o‘lchanadigan natijaga',
                    'subtitle' => 'Agentlik strategik rejalashtirishning yagona tizimini shakllantiradi: tahlil va tashabbuslarni tayyorlashdan tortib muvofiqlashtirish, monitoring va erishilgan natijalarni baholashgacha.',
                    'cta_text' => 'Agentlik haqida',
                    'cta2_text' => 'O‘zbekiston — 2030',
                ],
                'data' => [
                    'media_type' => 'image',
                    'image' => '/uploads/public/demo-agency-hero.jpg',
                    'cta_enabled' => '1', 'cta_url' => '/o-nas', 'cta_icon' => 'arrow-right',
                    'cta2_enabled' => '1', 'cta2_url' => '/strategiya-2030', 'cta2_style' => 'ghost',
                ],
            ],
            [
                'ru' => [
                    'eyebrow' => 'Приоритеты',
                    'title' => 'Цифровая трансформация государственных услуг',
                    'subtitle' => 'Единая система показателей, межведомственный обмен данными и сокращение сроков предоставления услуг.',
                    'cta_text' => 'Проекты',
                    'cta2_text' => '',
                ],
                'uz' => [
                    'eyebrow' => 'Ustuvor yo‘nalishlar',
                    'title' => 'Davlat xizmatlarining raqamli transformatsiyasi',
                    'subtitle' => 'Yagona ko‘rsatkichlar tizimi, idoralararo ma’lumot almashinuvi va xizmatlar muddatlarining qisqarishi.',
                    'cta_text' => 'Loyihalar',
                    'cta2_text' => '',
                ],
                'data' => [
                    'media_type' => 'image',
                    'image' => '/uploads/public/demo-urban-development.jpg',
                    'overlay' => 'gradient', 'overlay_opacity' => '55',
                    'cta_enabled' => '1', 'cta_url' => '/projects', 'cta_icon' => 'arrow-right',
                ],
            ],
            [
                'ru' => [
                    'eyebrow' => 'Устойчивое развитие',
                    'title' => 'Зелёная энергетика и рациональное природопользование',
                    'subtitle' => 'Повышение энергоэффективности, развитие солнечной и ветровой генерации.',
                    'cta_text' => 'Пресс-центр',
                    'cta2_text' => '',
                ],
                'uz' => [
                    'eyebrow' => 'Barqaror rivojlanish',
                    'title' => 'Yashil energetika va tabiatdan oqilona foydalanish',
                    'subtitle' => 'Energiya samaradorligini oshirish, quyosh va shamol generatsiyasini rivojlantirish.',
                    'cta_text' => 'Matbuot markazi',
                    'cta2_text' => '',
                ],
                'data' => [
                    'media_type' => 'image',
                    'image' => '/uploads/public/demo-green-energy.jpg',
                    'cta_enabled' => '1', 'cta_url' => '/press-centr', 'cta_icon' => 'arrow-right',
                ],
            ],
            [
                // Светлый кадр. Демо показывало только тёмные обложки, и по
                // нему нельзя было увидеть вторую половину поведения: белая
                // вуаль осветляет фотографию, по ней же выбирается тёмный
                // текст, а прозрачная шапка переходит в тёмный набор —
                // логотип, меню и иконки перекрашиваются вместе с кадром.
                // Заодно в заголовке показано выделение слова звёздочками.
                'ru' => [
                    'eyebrow' => 'Открытость',
                    'title' => 'Аналитика и данные — в *открытом* доступе',
                    'subtitle' => 'Показатели реформ, отчёты и наборы данных публикуются открыто: их можно скачать, перепроверить и использовать в собственных расчётах.',
                    'cta_text' => 'Аналитика',
                    'cta2_text' => '',
                ],
                'uz' => [
                    'eyebrow' => 'Ochiqlik',
                    'title' => 'Tahlil va ma’lumotlar — *ochiq* foydalanishda',
                    'subtitle' => 'Islohotlar ko‘rsatkichlari, hisobotlar va ma’lumotlar to‘plamlari ochiq e’lon qilinadi: ularni yuklab olish, qayta tekshirish va o‘z hisob-kitoblaringizda ishlatish mumkin.',
                    'cta_text' => 'Tahlil',
                    'cta2_text' => '',
                ],
                'data' => [
                    'media_type' => 'image',
                    'image' => '/uploads/public/demo-strategy-meeting.jpg',
                    'overlay' => 'solid', 'overlay_color' => '#ffffff', 'overlay_opacity' => '62',
                    'cta_enabled' => '1', 'cta_url' => '/analitika', 'cta_icon' => 'arrow-right',
                ],
            ],
        ];

        $insertSlide = $pdo->prepare(
            'INSERT INTO hero_slides (hero_id, title, sort_order, is_active, data, created_at)
             VALUES (:hero, :title, :sort, 1, :data, NOW())'
        );
        $insertTranslation = $pdo->prepare(
            'INSERT INTO hero_slide_translations (slide_id, lang, eyebrow, title, subtitle, cta_text, cta2_text)
             VALUES (:slide, :lang, :eyebrow, :title, :subtitle, :cta, :cta2)'
        );

        foreach ($slides as $order => $slide) {
            $data = \App\Core\Hero\HeroSlideData::normalize(array_merge($slide['data'], [
                'eyebrow' => $slide['ru']['eyebrow'],
                'title' => $slide['ru']['title'],
                'subtitle' => $slide['ru']['subtitle'],
                'cta_text' => $slide['ru']['cta_text'],
                'cta2_text' => $slide['ru']['cta2_text'],
            ]));
            $insertSlide->execute([
                ':hero' => $heroId,
                ':title' => $data['title'],
                ':sort' => $order,
                ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ]);
            $insertTranslation->execute([
                ':slide' => (int) $pdo->lastInsertId(),
                ':lang' => 'uz',
                ':eyebrow' => $slide['uz']['eyebrow'],
                ':title' => $slide['uz']['title'],
                ':subtitle' => $slide['uz']['subtitle'],
                ':cta' => $slide['uz']['cta_text'],
                ':cta2' => $slide['uz']['cta2_text'] !== '' ? $slide['uz']['cta2_text'] : null,
            ]);
        }

        // Блок «Обложка» на главной начинает ссылаться на запись. Собственные
        // поля блока остаются в данных: редактор может вернуться к прежнему
        // способу, ничего не потеряв.
        $heroBlocks = $pdo->query(
            "SELECT b.id, b.data FROM blocks b
             INNER JOIN pages p ON p.id = b.page_id
             WHERE b.type = 'hero' AND (p.is_home = 1 OR p.slug = 'home')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $updateBlock = $pdo->prepare('UPDATE blocks SET data = :data WHERE id = :id');
        foreach ($heroBlocks as $block) {
            $data = json_decode((string) $block['data'], true);
            if (!is_array($data)) {
                $data = [];
            }
            $data['hero_id'] = $heroId;
            $updateBlock->execute([
                ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ':id' => (int) $block['id'],
            ]);
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
            );
            $stmt->execute([':name' => $table]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :name
             LIMIT 1'
        );
        $stmt->execute([':name' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Есть ли колонка. Демо ставят и на базу, где свежая миграция ещё не
     * применена, — без проверки установка падала бы на неизвестном поле.
     */
    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if (!self::tableExists($pdo, $table)) {
            return false;
        }
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c
             LIMIT 1'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (bool) $stmt->fetchColumn();
    }

    private static function storeVersion(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'settings')) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO settings (`key`, `value`, updated_at)
             VALUES ('demo_data_version', :version, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()"
        );
        $stmt->execute([':version' => self::DEMO_VERSION]);
    }

    /**
     * Canonical form for decoded JSON: object key order is irrelevant, while
     * list order and scalar types remain strict. MySQL JSON normalizes object
     * keys, so direct PHP array identity can reject untouched starter data.
     */
    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalJsonValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalJsonValue($item);
        }

        return $value;
    }

    private static function isUntouchedStarterHome(PDO $pdo, int $pageId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id, type, title, data FROM blocks
             WHERE page_id = :page_id AND parent_block_id IS NULL ORDER BY sort_order, id'
        );
        $stmt->execute([':page_id' => $pageId]);
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $expected = [
            ['cta', 'Hero', ['title' => 'Официальный сайт организации', 'text' => 'Актуальная информация, документы, новости и услуги в одном месте.', 'button_text' => 'Последние новости', 'button_url' => '/news', '_spacing' => 'max']],
            ['columns', 'Быстрые ссылки', ['columns' => 3, 'gap' => 'medium', '_spacing' => 'premium']],
            ['news_latest', 'Последние новости', ['title' => 'Последние новости', 'limit' => 3, '_spacing' => 'premium']],
        ];
        if (count($blocks) !== count($expected)) {
            return false;
        }
        foreach ($blocks as $i => $block) {
            $data = json_decode((string) ($block['data'] ?? ''), true);
            if (($block['type'] ?? '') !== $expected[$i][0]
                || ($block['title'] ?? '') !== $expected[$i][1]
                || self::canonicalJsonValue($data) !== self::canonicalJsonValue($expected[$i][2])) {
                return false;
            }
        }

        $columnsId = (int) ($blocks[1]['id'] ?? 0);
        $childStmt = $pdo->prepare('SELECT data FROM blocks WHERE parent_block_id = :id ORDER BY column_index, sort_order, id');
        $childStmt->execute([':id' => $columnsId]);
        $children = $childStmt->fetchAll(PDO::FETCH_COLUMN);
        $expectedUrls = ['/catalog/documenty', '/catalog/vakansii', '/catalog/tendery'];
        if (count($children) !== count($expectedUrls)) {
            return false;
        }
        foreach ($children as $i => $json) {
            $data = json_decode((string) $json, true);
            if (!is_array($data) || ($data['button_url'] ?? '') !== $expectedUrls[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function pageIdForLang(PDO $pdo, string $slug, string $lang): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM pages
             WHERE slug = :slug AND lang = :lang AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([':slug' => $slug, ':lang' => $lang]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }
}
