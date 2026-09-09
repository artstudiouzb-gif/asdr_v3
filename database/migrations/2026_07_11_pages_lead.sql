-- Видимый лид/подзаголовок страницы (переводимый). Показывается под заголовком
-- на простых страницах (без hero-блока).
ALTER TABLE pages ADD COLUMN `lead` TEXT NULL AFTER meta_description;
ALTER TABLE page_translations ADD COLUMN `lead` TEXT NULL AFTER meta_description;
