-- @post-schema
-- Страховочная миграция после добавления языковых URL Hero.
-- Идемпотентна: подходит и для базы, где предыдущая миграция была выполнена
-- частично или ошибочно отмечена как применённая.
-- Откат: ALTER TABLE hero_slide_translations
--   DROP COLUMN cta_url, DROP COLUMN cta2_url, DROP COLUMN link_url;

SET @asdr_schema := DATABASE();

SET @asdr_sql := IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @asdr_schema
          AND TABLE_NAME = 'hero_slide_translations'
          AND COLUMN_NAME = 'cta_url'
    ),
    'SELECT 1',
    'ALTER TABLE hero_slide_translations ADD COLUMN cta_url VARCHAR(2048) NULL AFTER cta_text'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql := IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @asdr_schema
          AND TABLE_NAME = 'hero_slide_translations'
          AND COLUMN_NAME = 'cta2_url'
    ),
    'SELECT 1',
    'ALTER TABLE hero_slide_translations ADD COLUMN cta2_url VARCHAR(2048) NULL AFTER cta2_text'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql := IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @asdr_schema
          AND TABLE_NAME = 'hero_slide_translations'
          AND COLUMN_NAME = 'link_url'
    ),
    'SELECT 1',
    'ALTER TABLE hero_slide_translations ADD COLUMN link_url VARCHAR(2048) NULL AFTER cta2_url'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql := NULL;
SET @asdr_schema := NULL;
