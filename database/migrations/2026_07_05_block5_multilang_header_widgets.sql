-- Миграция Блока 5: мультиязычность, конструктор меню и header, боковые виджеты.
-- Применяется поверх исходной schema.sql (этапы 1-2).
-- Запуск: mysql -u USER -p DATABASE < database/migrations/2026_07_05_block5_multilang_header_widgets.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS languages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(8) NOT NULL,
    name            VARCHAR(60) NOT NULL,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_languages_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO languages (code, name, is_default, is_active, sort_order) VALUES
    ('ru', 'Русский', 1, 1, 0),
    ('uz', 'Oʻzbekcha', 0, 1, 1)
ON DUPLICATE KEY UPDATE code = code;

ALTER TABLE pages
    ADD COLUMN layout_type ENUM('no_sidebar', 'left_sidebar', 'right_sidebar')
        NOT NULL DEFAULT 'no_sidebar' AFTER is_home;

ALTER TABLE news
    ADD COLUMN meta_title VARCHAR(255) NULL AFTER image,
    ADD COLUMN meta_description VARCHAR(500) NULL AFTER meta_title;

CREATE TABLE IF NOT EXISTS page_translations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id         INT UNSIGNED NOT NULL,
    lang            VARCHAR(8) NOT NULL,
    title           VARCHAR(255) NULL,
    meta_title      VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    UNIQUE KEY uq_page_translations (page_id, lang),
    CONSTRAINT fk_page_translations_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE blocks
    ADD COLUMN lang VARCHAR(8) NOT NULL DEFAULT '' AFTER page_id,
    DROP KEY idx_blocks_page,
    ADD KEY idx_blocks_page (page_id, lang, sort_order);

-- Привязываем существующие блоки к языку по умолчанию.
UPDATE blocks SET lang = (SELECT code FROM languages WHERE is_default = 1 LIMIT 1) WHERE lang = '';

CREATE TABLE IF NOT EXISTS news_translations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id         INT UNSIGNED NOT NULL,
    lang            VARCHAR(8) NOT NULL,
    title           VARCHAR(255) NULL,
    excerpt         TEXT NULL,
    content         LONGTEXT NULL,
    meta_title      VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    UNIQUE KEY uq_news_translations (news_id, lang),
    CONSTRAINT fk_news_translations_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lang            VARCHAR(8) NOT NULL DEFAULT '',
    title           VARCHAR(190) NOT NULL,
    url_type        ENUM('page', 'news_index', 'custom') NOT NULL DEFAULT 'custom',
    url_value       VARCHAR(500) NULL,
    parent_id       INT UNSIGNED NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_menu_items_lang (lang, sort_order),
    CONSTRAINT fk_menu_items_parent FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS widgets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sidebar         ENUM('left', 'right') NOT NULL,
    type            VARCHAR(60) NOT NULL,
    title           VARCHAR(190) NULL,
    lang            VARCHAR(8) NOT NULL DEFAULT '',
    data            JSON NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_widgets_sidebar (sidebar, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
    ('header_config', '{"logo_position":"left","menu_position":"right","language_switcher":{"enabled":true,"format":"code"},"social_buttons":[],"cta":{"enabled":false,"text":"","url":"","style":"filled"}}')
ON DUPLICATE KEY UPDATE `key` = `key`;

SET FOREIGN_KEY_CHECKS = 1;
