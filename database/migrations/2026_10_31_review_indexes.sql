-- Индексы по итогам ревизии производительности (октябрь 2026, план 1.4).
--
-- Идемпотентно через information_schema: «ADD KEY IF NOT EXISTS» в MySQL 8 нет.

-- Списки новостей не основного языка и производная таблица групп
-- (News::publicLanguageParts): lang шёл фильтром после idx_news_listing,
-- то есть по строкам; хвост translation_group_id делает индекс покрывающим.
SET @asdr_has_0 := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news'
      AND INDEX_NAME = 'idx_news_lang_listing'
);
SET @asdr_sql := IF(
    @asdr_has_0 = 0,
    'ALTER TABLE news ADD KEY idx_news_lang_listing (lang, status, deleted_at, published_at, translation_group_id)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

-- Проекты одного языка (Project::published/forHome, виджет): в
-- idx_pages_projects нет lang.
SET @asdr_has_1 := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pages'
      AND INDEX_NAME = 'idx_pages_projects_lang'
);
SET @asdr_sql := IF(
    @asdr_has_1 = 0,
    'ALTER TABLE pages ADD KEY idx_pages_projects_lang (entity_type, lang, status, deleted_at, is_featured, sort_order)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

-- Поиск файла по имени на диске (FileEntry::findPublicByUrl, MediaCleaner,
-- импорты): прежде полный проход по files на каждую фотографию.
SET @asdr_has_2 := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'files'
      AND INDEX_NAME = 'idx_files_stored_name'
);
SET @asdr_sql := IF(
    @asdr_has_2 = 0,
    'ALTER TABLE files ADD KEY idx_files_stored_name (stored_name)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

-- Медиатека: access_type='public' ORDER BY created_at DESC LIMIT 48.
SET @asdr_has_3 := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'files'
      AND INDEX_NAME = 'idx_files_access_created'
);
SET @asdr_sql := IF(
    @asdr_has_3 = 0,
    'ALTER TABLE files ADD KEY idx_files_access_created (access_type, created_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;
