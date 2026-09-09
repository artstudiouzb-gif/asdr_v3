-- Необязательная иерархия страниц.
-- parent_id влияет на навигацию и хлебные крошки, но не меняет плоский URL.

SET @has_pages_parent = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pages'
      AND COLUMN_NAME = 'parent_id'
);
SET @add_pages_parent = IF(
    @has_pages_parent = 0,
    'ALTER TABLE pages ADD COLUMN parent_id INT UNSIGNED NULL COMMENT ''родительская страница; URL страницы остаётся плоским'' AFTER translation_group_id',
    'SELECT 1'
);
PREPARE stmt FROM @add_pages_parent;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_pages_parent_index = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pages'
      AND INDEX_NAME = 'idx_pages_parent'
);
SET @add_pages_parent_index = IF(
    @has_pages_parent_index = 0,
    'ALTER TABLE pages ADD INDEX idx_pages_parent (parent_id)',
    'SELECT 1'
);
PREPARE stmt FROM @add_pages_parent_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_pages_parent_fk = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pages'
      AND CONSTRAINT_NAME = 'fk_pages_parent'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @add_pages_parent_fk = IF(
    @has_pages_parent_fk = 0,
    'ALTER TABLE pages ADD CONSTRAINT fk_pages_parent FOREIGN KEY (parent_id) REFERENCES pages(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @add_pages_parent_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
