-- @post-schema
-- Редакционные метаданные медиабиблиотеки.
-- Изображение загружается и описывается до привязки к новости/странице.
--
-- MySQL 8 не поддерживает условное добавление колонки в форме MariaDB.
-- Каждая колонка поэтому добавляется через проверку information_schema и
-- динамический DDL. Такой вариант безопасен и при повторном запуске после
-- частично выполненной миграции.

SET @asdr_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'files'
          AND COLUMN_NAME = 'alt_text'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `alt_text` VARCHAR(255) NULL AFTER `uploaded_by`'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'files'
          AND COLUMN_NAME = 'caption'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `caption` VARCHAR(255) NULL AFTER `alt_text`'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'files'
          AND COLUMN_NAME = 'description'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `description` TEXT NULL AFTER `caption`'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'files'
          AND COLUMN_NAME = 'credit'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `credit` VARCHAR(255) NULL AFTER `description`'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'files'
          AND COLUMN_NAME = 'focal_x'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `focal_x` TINYINT UNSIGNED NULL AFTER `credit`'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'files'
          AND COLUMN_NAME = 'focal_y'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `focal_y` TINYINT UNSIGNED NULL AFTER `focal_x`'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'files'
          AND COLUMN_NAME = 'metadata_updated_at'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `metadata_updated_at` DATETIME NULL AFTER `focal_y`'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;
