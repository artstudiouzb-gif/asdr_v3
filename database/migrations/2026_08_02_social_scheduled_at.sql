-- Отложенная публикация в соцсети: время, раньше которого задачу брать нельзя.
-- NULL — отправить при ближайшем запуске воркера (прежнее поведение).
-- Шаги идемпотентны: колонка и индекс добавляются, только если их нет.

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'social_posts'
      AND COLUMN_NAME = 'scheduled_at'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE social_posts ADD COLUMN scheduled_at DATETIME NULL AFTER status',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'social_posts'
      AND INDEX_NAME = 'idx_social_posts_scheduled'
);
SET @asdr_sql := IF(
    @asdr_has_index = 0,
    'ALTER TABLE social_posts ADD KEY idx_social_posts_scheduled (status, scheduled_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
