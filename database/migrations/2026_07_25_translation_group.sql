-- Миграция: поддержка независимых языковых постов и проектов
-- Добавляет колонки lang и translation_group_id в таблицы news, pages, projects

SET @exist_news_lang = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND COLUMN_NAME = 'lang');
SET @sql_news_lang = IF(@exist_news_lang = 0, 'ALTER TABLE news ADD COLUMN lang VARCHAR(8) NOT NULL DEFAULT ''ru''', 'SELECT 1');
PREPARE stmt FROM @sql_news_lang; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist_news_tg = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND COLUMN_NAME = 'translation_group_id');
SET @sql_news_tg = IF(@exist_news_tg = 0, 'ALTER TABLE news ADD COLUMN translation_group_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql_news_tg; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist_pages_lang = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'lang');
SET @sql_pages_lang = IF(@exist_pages_lang = 0, 'ALTER TABLE pages ADD COLUMN lang VARCHAR(8) NOT NULL DEFAULT ''ru''', 'SELECT 1');
PREPARE stmt FROM @sql_pages_lang; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist_pages_tg = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'translation_group_id');
SET @sql_pages_tg = IF(@exist_pages_tg = 0, 'ALTER TABLE pages ADD COLUMN translation_group_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql_pages_tg; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist_projects_lang = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'lang');
SET @sql_projects_lang = IF(@exist_projects_lang = 0, 'ALTER TABLE projects ADD COLUMN lang VARCHAR(8) NOT NULL DEFAULT ''ru''', 'SELECT 1');
PREPARE stmt FROM @sql_projects_lang; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist_projects_tg = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'translation_group_id');
SET @sql_projects_tg = IF(@exist_projects_tg = 0, 'ALTER TABLE projects ADD COLUMN translation_group_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql_projects_tg; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE news SET translation_group_id = id WHERE translation_group_id IS NULL;
UPDATE pages SET translation_group_id = id WHERE translation_group_id IS NULL;
UPDATE projects SET translation_group_id = id WHERE translation_group_id IS NULL;
