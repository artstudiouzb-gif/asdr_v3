-- Завершает переход slug на языковую уникальность для существующих установок.
-- Раньше эти индексы создавались лениво при открытии страницы админки.

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND INDEX_NAME = 'uq_news_slug'
);
SET @asdr_sql := IF(@asdr_has_index > 0, 'ALTER TABLE news DROP INDEX uq_news_slug', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND INDEX_NAME = 'uq_news_slug_lang'
);
SET @asdr_sql := IF(@asdr_has_index = 0, 'ALTER TABLE news ADD UNIQUE KEY uq_news_slug_lang (slug, lang)', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND INDEX_NAME = 'idx_news_lang_group'
);
SET @asdr_sql := IF(@asdr_has_index = 0, 'ALTER TABLE news ADD KEY idx_news_lang_group (translation_group_id, lang)', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND INDEX_NAME = 'uq_pages_slug'
);
SET @asdr_sql := IF(@asdr_has_index > 0, 'ALTER TABLE pages DROP INDEX uq_pages_slug', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND INDEX_NAME = 'uq_pages_slug_lang'
);
SET @asdr_sql := IF(@asdr_has_index = 0, 'ALTER TABLE pages ADD UNIQUE KEY uq_pages_slug_lang (slug, lang)', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND INDEX_NAME = 'idx_pages_lang_group'
);
SET @asdr_sql := IF(@asdr_has_index = 0, 'ALTER TABLE pages ADD KEY idx_pages_lang_group (translation_group_id, lang)', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'uq_projects_slug'
);
SET @asdr_sql := IF(@asdr_has_index > 0, 'ALTER TABLE projects DROP INDEX uq_projects_slug', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'uq_projects_slug_lang'
);
SET @asdr_sql := IF(@asdr_has_index = 0, 'ALTER TABLE projects ADD UNIQUE KEY uq_projects_slug_lang (slug, lang)', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'idx_projects_lang_group'
);
SET @asdr_sql := IF(@asdr_has_index = 0, 'ALTER TABLE projects ADD KEY idx_projects_lang_group (translation_group_id, lang)', 'SELECT 1');
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
