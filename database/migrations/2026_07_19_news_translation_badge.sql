-- Рубрика новости переводится: на узбекской странице бейдж больше не
-- показывается по-русски рядом с переведённым заголовком.
ALTER TABLE news_translations
    ADD COLUMN badge VARCHAR(100) NULL COMMENT 'бейдж категории' AFTER title;
