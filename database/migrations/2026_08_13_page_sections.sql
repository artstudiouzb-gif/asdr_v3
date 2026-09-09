-- Страница-раздел: обычная страница, назначенная шапкой ленты новостей или
-- списка проектов. Заголовок, лид, SEO и блоки этих разделов до сих пор жили
-- в шаблонах и в админке не редактировались.
ALTER TABLE pages
    ADD COLUMN section VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'раздел, шапкой которого служит страница: news|projects' AFTER entity_type,
    ADD KEY idx_pages_section (section, lang, status);
