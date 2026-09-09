-- 2026_08_05_page_custom_assets.sql
-- Добавление колонок custom_css и custom_js в таблицу pages для внешних/кастомных стилей и скриптов

ALTER TABLE pages
    ADD COLUMN custom_css TEXT NULL AFTER transparent_header,
    ADD COLUMN custom_js TEXT NULL AFTER custom_css;
