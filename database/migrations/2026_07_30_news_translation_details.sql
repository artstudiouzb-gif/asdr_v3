-- Переносит расширенные поля детальной новости в штатную миграцию.
-- Каждый шаг безопасен после частично выполненного прежнего runtime-DDL.

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_translations'
      AND COLUMN_NAME = 'key_points'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_translations ADD COLUMN key_points TEXT NULL AFTER hashtags',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_translations'
      AND COLUMN_NAME = 'event_meta'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_translations ADD COLUMN event_meta TEXT NULL AFTER key_points',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_translations'
      AND COLUMN_NAME = 'timeline_json'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_translations ADD COLUMN timeline_json JSON NULL AFTER event_meta',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_translations'
      AND COLUMN_NAME = 'docs'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_translations ADD COLUMN docs TEXT NULL AFTER timeline_json',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_translations'
      AND COLUMN_NAME = 'poll_question'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_translations ADD COLUMN poll_question VARCHAR(255) NULL AFTER docs',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_translations'
      AND COLUMN_NAME = 'poll_options_json'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_translations ADD COLUMN poll_options_json JSON NULL AFTER poll_question',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
