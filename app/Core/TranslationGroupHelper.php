<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;
use PDO;

final class TranslationGroupHelper
{
    private const NEWS_TRANSLATION_DRAFT_SLUG_PREFIX = 'news-translation-draft-';

    private static bool $schemaEnsured = false;

    public static function isProvisionalNewsSlug(string $slug): bool
    {
        return preg_match(
            '/^' . preg_quote(self::NEWS_TRANSLATION_DRAFT_SLUG_PREFIX, '/') . '[a-f0-9]{12}(?:-\d+)?$/',
            $slug
        ) === 1;
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured || !Database::isConnected()) {
            return;
        }

        // Проекты живут в pages, отдельной таблицы у них нет.
        $tables = ['news', 'pages'];
        $pdo = Database::pdo();
        $missing = [];
        foreach ($tables as $table) {
            $cols = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach (['lang', 'translation_group_id'] as $column) {
                if (!in_array($column, $cols, true)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                'Схема переводов не обновлена (' . implode(', ', $missing)
                . '). Выполните php database/migrate.php.'
            );
        }

        self::$schemaEnsured = true;
    }

    /**
     * Автоматически связывает неручные/разрозненные записи (например "Главная (UZ)" или slug "home-uz")
     * с их родительским материалом на основном языке, если они ещё не привязаны.
     */
    public static function autoLinkStandaloneTranslations(): void
    {
        self::ensureSchema();

        $defaultLang = Language::defaultCode();
        $tables = ['pages', 'news'];
        $pdo = Database::pdo();

        foreach ($tables as $table) {
            // Связывание по похожим заголовкам — приём для старых страниц и
            // новостей. Проекты (тот же pages) под него не попадают: у них
            // языковая версия всегда заводится с готовой группой.
            $typeWhere = $table === 'pages' ? " AND entity_type = 'page'" : '';
            try {
                // Ищем все записи, где lang != defaultLang и (translation_group_id IS NULL или translation_group_id = id)
                $stmt = $pdo->prepare("SELECT id, title, slug, lang" . ($table === 'pages' ? ', is_home' : '') . " FROM {$table} WHERE lang != :default_lang AND (translation_group_id IS NULL OR translation_group_id = 0 OR translation_group_id = id) AND deleted_at IS NULL{$typeWhere}");
                $stmt->execute([':default_lang' => $defaultLang]);
                $unlinked = $stmt->fetchAll();

                foreach ($unlinked as $row) {
                    $id = (int) $row['id'];
                    $title = (string) ($row['title'] ?? '');
                    $slug = (string) ($row['slug'] ?? '');
                    $isHome = !empty($row['is_home']);

                    // Очищаем заголовок и slug от языковых суффиксов "(UZ)", "-uz", "_uz", "(EN)", "-en"
                    $cleanTitle = trim(preg_replace('/\s*\((?:uz|ru|en)\)\s*$/i', '', $title));
                    $cleanSlug = trim(preg_replace('/[_\-](?:uz|ru|en)\d*$/i', '', $slug));

                    // Ищем основной элемент на дефолтном языке с похожим заголовком или чистым slug
                    $stmtMatch = $pdo->prepare(
                        "SELECT id FROM {$table} 
                         WHERE lang = :default_lang 
                           AND deleted_at IS NULL{$typeWhere}
                           AND (title = :clean_title OR slug = :clean_slug OR title = :orig_title OR slug = :orig_slug)
                         ORDER BY id ASC LIMIT 1"
                    );
                    $stmtMatch->execute([
                        ':default_lang' => $defaultLang,
                        ':clean_title' => $cleanTitle,
                        ':clean_slug' => $cleanSlug,
                        ':orig_title' => $title,
                        ':orig_slug' => $slug,
                    ]);
                    $parentId = $stmtMatch->fetchColumn();

                    // Для страниц — специальный фолбэк для главных страниц ("Главная", "Bosh sahifa", "home", is_home = 1)
                    if (!$parentId && $table === 'pages' && (
                        $isHome ||
                        mb_stripos($title, 'главная') !== false ||
                        mb_stripos($title, 'bosh sahifa') !== false ||
                        mb_stripos($slug, 'home') !== false ||
                        mb_stripos($slug, 'bosh-sahifa') !== false
                    )) {
                        $homeStmt = $pdo->prepare(
                            "SELECT id FROM pages
                             WHERE (is_home = 1 OR slug = 'home' OR lang = :default_lang)
                               AND entity_type = 'page' AND deleted_at IS NULL
                             ORDER BY is_home DESC, id ASC LIMIT 1"
                        );
                        $homeStmt->execute([':default_lang' => $defaultLang]);
                        $parentId = $homeStmt->fetchColumn();
                    }

                    if ($parentId !== false && (int) $parentId > 0 && (int) $parentId !== $id) {
                        $parentSlug = (string) $pdo->query("SELECT slug FROM {$table} WHERE id = " . (int) $parentId)->fetchColumn();
                        if ($table !== 'news' && $parentSlug !== '' && $parentSlug !== $slug && !$isHome && $slug !== 'bosh-sahifa' && $slug !== 'home') {
                            $pdo->prepare("UPDATE {$table} SET translation_group_id = :parent_id, slug = :parent_slug WHERE id = :id")
                                ->execute([':parent_id' => (int) $parentId, ':parent_slug' => $parentSlug, ':id' => $id]);
                        } else {
                            $pdo->prepare("UPDATE {$table} SET translation_group_id = :parent_id WHERE id = :id")
                                ->execute([':parent_id' => (int) $parentId, ':id' => $id]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Досвязывание — необязательная починка исторических данных, и
                // сбой на одной таблице не должен ронять остальные. Но молча
                // терять его нельзя: без записи в лог рассыпавшиеся группы
                // переводов невозможно диагностировать.
                Logger::error(sprintf(
                    'TranslationGroupHelper: не удалось досвязать переводы в таблице %s: %s',
                    $table,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>> переводы с ключом — кодом языка (например, 'ru' => [...], 'uz' => [...])
     */
    /**
     * Физическая таблица и условие подтипа для раздела админки: проект — это
     * строка pages с entity_type='project', отдельной таблицы у него нет.
     *
     * @return array{0:string,1:string}
     */
    private static function tableFor(string $module): array
    {
        return match ($module) {
            'projects' => ['pages', " AND entity_type = 'project'"],
            'pages' => ['pages', " AND entity_type = 'page'"],
            default => ['news', ''],
        };
    }

    public static function getTranslations(string $module, int $recordId): array
    {
        self::ensureSchema();

        [$table, $typeWhere] = self::tableFor($module);
        $stmt = Database::pdo()->prepare("SELECT * FROM {$table} WHERE id = :id{$typeWhere} LIMIT 1");
        $stmt->execute([':id' => $recordId]);
        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }

        // Ноль означает «группы нет», как и NULL: остальной код читает колонку
        // через COALESCE(NULLIF(...,0), id). Здесь стоял `??`, который ноль
        // пропускал, — и запись оказывалась в несуществующей «группе 0»,
        // теряя свои переводы. На живых данных колонка бывает NULL или равна
        // своему id, поэтому вывод не меняется; расходились только правила.
        $groupId = (int) ($row['translation_group_id'] ?: $recordId);
        // Порядок закреплён: без него он зависит от плана запроса и разъезжается
        // между MySQL и MariaDB — на одних и тех же данных языки возвращались в
        // разном порядке, и это протекало в порядок hreflang карты сайта.
        $stmtGroup = Database::pdo()->prepare(
            "SELECT * FROM {$table} WHERE (translation_group_id = :gid OR id = :gid2)
               AND deleted_at IS NULL{$typeWhere}
             ORDER BY id"
        );
        $stmtGroup->execute([':gid' => $groupId, ':gid2' => $groupId]);

        $translations = [];
        foreach ($stmtGroup->fetchAll() as $t) {
            $langCode = (string) ($t['lang'] ?? Language::defaultCode());
            $translations[$langCode] = $t;
        }

        return $translations;
    }

    /**
     * Пути опубликованных языковых версий записи: код языка → путь без
     * языкового префикса. Учитывает оба механизма перевода — реализация
     * общая, в App\Core\Translations.
     *
     * @return array<string,string>
     */
    public static function publishedPaths(string $table, int $recordId, string $prefix = ''): array
    {
        return Translations::paths($table, $recordId, $prefix);
    }

    /**
     * Создаёт новую отдельную запись-перевод для выбранного языка.
     */
    public static function createTranslation(string $module, int $originalId, string $targetLang): int
    {
        self::ensureSchema();

        if (!in_array($module, ['news', 'pages', 'projects'], true)) {
            throw new \InvalidArgumentException("Неподдерживаемый раздел {$module}");
        }

        [$table, $typeWhere] = self::tableFor($module);

        $pdo = Database::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $groupStmt = $pdo->prepare(
                "SELECT COALESCE(NULLIF(translation_group_id, 0), id)
                 FROM {$table}
                 WHERE id = :id AND deleted_at IS NULL{$typeWhere}
                 LIMIT 1"
            );
            $groupStmt->execute([':id' => $originalId]);
            $groupId = $groupStmt->fetchColumn();
            if ($groupId === false) {
                throw new \InvalidArgumentException("Запись #{$originalId} не найдена в таблице {$table}");
            }
            $groupId = (int) $groupId;

            // Все запросы одной языковой группы блокируют один и тот же набор
            // строк в стабильном порядке. Это сериализует создание перевода,
            // даже если запросы пришли с разных языковых версий или старый
            // числовой идентификатор корня больше не существует.
            $groupLock = $pdo->prepare(
                "SELECT id
                 FROM {$table}
                 WHERE (id = :id OR translation_group_id = :id2){$typeWhere}
                 ORDER BY id
                 FOR UPDATE"
            );
            $groupLock->execute([':id' => $groupId, ':id2' => $groupId]);
            if ($groupLock->fetchAll(PDO::FETCH_COLUMN) === []) {
                throw new \RuntimeException("Не удалось заблокировать группу перевода #{$groupId}");
            }

            $stmt = $pdo->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE id = :id AND deleted_at IS NULL{$typeWhere}
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([':id' => $originalId]);
            $orig = $stmt->fetch();
            if (!$orig) {
                throw new \InvalidArgumentException("Запись #{$originalId} не найдена в таблице {$table}");
            }

            if ((int) ($orig['translation_group_id'] ?? 0) !== $groupId) {
                $pdo->prepare("UPDATE {$table} SET translation_group_id = :gid WHERE id = :id")
                    ->execute([':gid' => $groupId, ':id' => $originalId]);
            }

            // После блокировки корня повторно проверяем наличие целевого языка.
            $stmtExist = $pdo->prepare(
                "SELECT id
                 FROM {$table}
                 WHERE translation_group_id = :gid
                   AND lang = :lang
                   AND deleted_at IS NULL{$typeWhere}
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmtExist->execute([':gid' => $groupId, ':lang' => $targetLang]);
            $existingId = $stmtExist->fetchColumn();
            if ($existingId !== false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return (int) $existingId;
            }

            $slugBase = match ($module) {
                // Черновик перевода новости не должен занимать читаемый адрес.
                'news' => self::NEWS_TRANSLATION_DRAFT_SLUG_PREFIX . bin2hex(random_bytes(6)),
                // У языковой версии проекта адрес тот же: уникальность slug
                // считается в пределах пары «тип + язык».
                'projects' => (string) ($orig['slug'] ?? 'project'),
                default => (string) ($orig['slug'] ?? 'item') . '-' . $targetLang,
            };
            $newSlug = Slug::unique(
                $slugBase,
                static function (string $candidate, ?int $_excludeId) use ($pdo, $table, $typeWhere, $targetLang): bool {
                    $check = $pdo->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE slug = :slug AND lang = :lang AND deleted_at IS NULL{$typeWhere}"
                    );
                    $check->execute([':slug' => $candidate, ':lang' => $targetLang]);
                    return (int) $check->fetchColumn() > 0;
                }
            );
            if ($module === 'news') {
                $ins = $pdo->prepare(
                    "INSERT INTO news (title, slug, excerpt, lead_html, badge, category_id, content, image, video_url, audio_url, audio_title, hashtags, press_release_url, key_points, event_meta, timeline_json, docs, source_note, layout_type, sidebar_layout, focal_x, focal_y, meta_title, meta_description, status, published_at, author_id, lang, translation_group_id, created_at)
                      VALUES (:t, :s, :e, :lh, :b, :cat, :c, :img, :v, :a, :at, :h, :pr, :kp, :em, :tj, :dc, :sn, :lt, :sl, :fx, :fy, :mt, :md, 'draft', NOW(), :auth, :lang, :gid, NOW())"
                );
                $ins->execute([
                    ':t' => '',
                    ':s' => $newSlug,
                    ':e' => null,
                    ':lh' => null,
                    ':b' => null,
                    // Категория — та же рубрика на другом языке, поэтому
                    // наследуется (в отличие от текстов, которые переводчик
                    // пишет с нуля). Переводится название самой категории.
                    ':cat' => !empty($orig['category_id']) ? (int) $orig['category_id'] : null,
                    ':c' => null,
                    ':img' => $orig['image'] ?? null,
                    ':v' => $orig['video_url'] ?? null,
                    ':a' => $orig['audio_url'] ?? null,
                    ':at' => null,
                    ':h' => null,
                    ':pr' => null,
                    ':kp' => null,
                    ':em' => null,
                    ':tj' => null,
                    ':dc' => null,
                    ':sn' => null,
                    ':lt' => $orig['layout_type'] ?? 'standard',
                    ':sl' => $orig['sidebar_layout'] ?? 'right_sidebar',
                    ':fx' => $orig['focal_x'] ?? null,
                    ':fy' => $orig['focal_y'] ?? null,
                    ':mt' => null,
                    ':md' => null,
                    ':auth' => $orig['author_id'] ?? null,
                    ':lang' => $targetLang,
                    ':gid' => $groupId,
                ]);
            } elseif ($module === 'pages') {
                $ins = $pdo->prepare(
                    "INSERT INTO pages (title, slug, meta_title, meta_description, `lead`, status, is_home, layout_type, hide_chrome, transparent_header, lang, translation_group_id, parent_id, created_at)
                     VALUES (:t, :s, :mt, :md, :l, 'draft', 0, :lt, :hc, :th, :lang, :gid, :parent_id, NOW())"
                );
                $ins->execute([
                    ':t' => ($orig['title'] ?? '') . ' (' . strtoupper($targetLang) . ')',
                    ':s' => $newSlug,
                    ':mt' => $orig['meta_title'] ?? null,
                    ':md' => $orig['meta_description'] ?? null,
                    ':l' => $orig['lead'] ?? null,
                    ':lt' => $orig['layout_type'] ?? 'no_sidebar',
                    ':hc' => $orig['hide_chrome'] ?? 0,
                    ':th' => $orig['transparent_header'] ?? 0,
                    ':lang' => $targetLang,
                    ':gid' => $groupId,
                    ':parent_id' => !empty($orig['parent_id']) ? (int) $orig['parent_id'] : null,
                ]);
            } else {
                // Проект — страница с подтипом: запись идёт в pages, анонс
                // карточки живёт в lead, тело копируется блоками ниже.
                $ins = $pdo->prepare(
                    "INSERT INTO pages (title, slug, entity_type, `lead`, cover_image, status, is_featured, sort_order, layout_type, lang, translation_group_id, created_at)
                     VALUES (:t, :s, 'project', :d, :ci, 'draft', :if, :so, 'no_sidebar', :lang, :gid, NOW())"
                );
                $ins->execute([
                    ':t' => ($orig['title'] ?? '') . ' (' . strtoupper($targetLang) . ')',
                    ':s' => $newSlug,
                    ':d' => $orig['description'] ?? null,
                    ':ci' => $orig['cover_image'] ?? null,
                    ':if' => $orig['is_featured'] ?? 0,
                    ':so' => $orig['sort_order'] ?? 0,
                    ':lang' => $targetLang,
                    ':gid' => $groupId,
                ]);
            }

            $newId = (int) $pdo->lastInsertId();
            if (in_array($module, ['pages', 'projects'], true) && $newId > 0) {
                self::copyBlocksForTranslation($pdo, $originalId, $newId, $targetLang);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $newId;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function copyBlocksForTranslation(
        PDO $pdo,
        int $origPageId,
        int $newPageId,
        string $targetLang
    ): void
    {
        $pageLangStmt = $pdo->prepare('SELECT lang FROM pages WHERE id = :id LIMIT 1');
        $pageLangStmt->execute([':id' => $origPageId]);
        $originalLang = (string) ($pageLangStmt->fetchColumn() ?: Language::defaultCode());

        // В старых данных RU и UZ могли лежать в одном page_id. Если там
        // уже есть стек целевого языка, переносим именно его; иначе берём
        // стек исходной страницы и назначаем копии новый язык.
        $sourceLang = null;
        $countByLang = $pdo->prepare(
            'SELECT COUNT(*) FROM blocks WHERE page_id = :page_id AND lang = :lang'
        );
        foreach (array_values(array_unique([$targetLang, $originalLang, ''])) as $candidateLang) {
            $countByLang->execute([
                ':page_id' => $origPageId,
                ':lang' => $candidateLang,
            ]);
            if ((int) $countByLang->fetchColumn() > 0) {
                $sourceLang = $candidateLang;
                break;
            }
        }
        if ($sourceLang === null) {
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM blocks
             WHERE page_id = :pid
               AND lang = :source_lang
               AND parent_block_id IS NULL
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':pid' => $origPageId, ':source_lang' => $sourceLang]);
        $topBlocks = $stmt->fetchAll();

        foreach ($topBlocks as $b) {
            $ins = $pdo->prepare(
                "INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, custom_css, sort_order, is_active, created_at)
                 VALUES (:pid, NULL, :ci, :lang, :type, :title, :data, :css, :so, :act, NOW())"
            );
            $ins->execute([
                ':pid' => $newPageId,
                ':ci' => $b['column_index'] ?? 0,
                ':lang' => $targetLang,
                ':type' => $b['type'],
                ':title' => $b['title'],
                ':data' => $b['data'],
                ':css' => $b['custom_css'] ?? null,
                ':so' => $b['sort_order'],
                ':act' => $b['is_active'] ?? 1,
            ]);
            $newParentId = (int) $pdo->lastInsertId();

            $stmtKids = $pdo->prepare(
                'SELECT *
                 FROM blocks
                 WHERE page_id = :page_id
                   AND parent_block_id = :parent_block_id
                   AND lang = :source_lang
                 ORDER BY sort_order ASC, id ASC'
            );
            $stmtKids->execute([
                ':page_id' => $origPageId,
                ':parent_block_id' => $b['id'],
                ':source_lang' => $sourceLang,
            ]);
            $kids = $stmtKids->fetchAll();

            foreach ($kids as $k) {
                $insKid = $pdo->prepare(
                    "INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, custom_css, sort_order, is_active, created_at)
                     VALUES (:pid, :pbid, :ci, :lang, :type, :title, :data, :css, :so, :act, NOW())"
                );
                $insKid->execute([
                    ':pid' => $newPageId,
                    ':pbid' => $newParentId,
                    ':ci' => $k['column_index'] ?? 0,
                    ':lang' => $targetLang,
                    ':type' => $k['type'],
                    ':title' => $k['title'],
                    ':data' => $k['data'],
                    ':css' => $k['custom_css'] ?? null,
                    ':so' => $k['sort_order'],
                    ':act' => $k['is_active'] ?? 1,
                ]);
            }
        }
    }

    /**
     * Рендерит боковой мета-бокс перевода записи.
     */
    public static function renderSidebarMetaBox(string $module, array $currentRecord): string
    {
        self::ensureSchema();

        $recordId = (int) ($currentRecord['id'] ?? 0);
        $currentLang = (string) ($currentRecord['lang'] ?? Language::defaultCode());
        $translations = $recordId > 0 ? self::getTranslations($module, $recordId) : [];
        $languages = Language::active();
        $defaultCode = Language::defaultCode();

        $currentLangName = strtoupper($currentLang);
        foreach ($languages as $l) {
            if ((string) ($l['code'] ?? '') === $currentLang) {
                $currentLangName = (string) ($l['name'] ?? strtoupper($currentLang));
                break;
            }
        }

        ob_start();
        ?>
        <div class="form-card multilang-group-card u-inline-c18e1e5580">
            <input type="hidden" name="lang" value="<?= htmlspecialchars($currentLang, ENT_QUOTES) ?>">
            <div class="u-inline-342ce139a0">
                <h3 class="u-inline-7defd547d2">
                    <?= Icon::render('world', 19, 'u-inline-aa5e5b184d') ?>
                    Язык и переводы
                </h3>
            </div>

            <!-- Верхняя выделенная плашка с текущим редактируемым языком -->
            <div class="multilang-editing">
                <div class="u-inline-e3f61041ef">
                    <span class="u-inline-7c3cfec592">Редактируется:</span>
                    <strong class="multilang-editing__code"><?= htmlspecialchars($currentLang, ENT_QUOTES) ?></strong>
                    <span class="u-inline-72088eb607">(<?= htmlspecialchars($currentLangName, ENT_QUOTES) ?>)</span>
                </div>
                <?php if ($currentLang === $defaultCode): ?>
                    <span class="u-inline-3159a647e8">Основной</span>
                <?php else: ?>
                    <span class="u-inline-4488ac4e31">Перевод</span>
                <?php endif; ?>
            </div>

            <div class="multilang-translation-list u-inline-f67b86e0fb">
                <?php foreach ($languages as $lang): ?>
                    <?php
                    $code = (string) $lang['code'];
                    $tRecord = $translations[$code] ?? null;
                    $isSelf = ($code === $currentLang);
                    ?>
                    <?php if ($isSelf): ?>
                        <div class="u-inline-9da576af61">
                            <div class="u-inline-e3f61041ef">
                                <span class="u-inline-26c741d857"></span>
                                <span class="u-inline-9ec672a78d"><?= htmlspecialchars($lang['name'], ENT_QUOTES) ?></span>
                                <?php if ($code === $defaultCode): ?>
                                    <span class="u-inline-c23eb2f888">(оригинал)</span>
                                <?php endif; ?>
                            </div>
                            <span class="u-inline-419b2840c7">
                                <?= Icon::render('check', 12, 'translation-current-icon', 3) ?>
                                Текущий пост
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="u-inline-0fc66a2344">
                            <div class="u-inline-e3f61041ef">
                                <span class="u-inline-3b3157a26b"><?= htmlspecialchars($lang['name'], ENT_QUOTES) ?></span>
                                <?php if ($code === $defaultCode): ?>
                                    <span class="u-inline-71577fef0b">(оригинал)</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($tRecord !== null): ?>
                                    <a href="/admin/<?= $module ?>/<?= (int) $tRecord['id'] ?>/edit" class="btn btn--small btn--secondary u-inline-30b9f76a48">
                                        <?= Icon::render('edit', 14) ?> Редактировать (#<?= (int) $tRecord['id'] ?>)
                                    </a>
                                <?php elseif ($recordId > 0): ?>
                                    <button type="submit"
                                            name="target_lang"
                                            value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                            formaction="/admin/<?= rawurlencode($module) ?>/<?= $recordId ?>/create-translation"
                                            formmethod="post"
                                            formnovalidate
                                            class="btn btn--small btn--primary u-inline-64c12efe40">
                                        <?= Icon::render('plus', 14) ?> Создать перевод
                                    </button>
                                <?php else: ?>
                                    <span class="u-inline-d716a45428">Сначала сохраните запись</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
