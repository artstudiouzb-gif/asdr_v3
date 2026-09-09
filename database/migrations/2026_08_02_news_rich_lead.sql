-- Форматированный лид хранится отдельно; excerpt остаётся чистым текстом для
-- карточек, поиска, SEO и каналов без HTML. Старые записи работают через
-- fallback на excerpt и заполнят lead_html при следующем сохранении.

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND COLUMN_NAME = 'lead_html'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news ADD COLUMN lead_html LONGTEXT NULL COMMENT ''форматированный лид; excerpt — его текстовая версия'' AFTER excerpt',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news_translations' AND COLUMN_NAME = 'lead_html'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_translations ADD COLUMN lead_html LONGTEXT NULL COMMENT ''форматированный лид перевода'' AFTER excerpt',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
