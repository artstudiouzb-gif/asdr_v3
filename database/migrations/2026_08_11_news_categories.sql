-- Категории новостей.
--
-- До этой миграции роль категории играл свободный текст в поле `news.badge`:
-- редактор вписывал строку руками, поэтому «Новости», «новости» и «Новости.»
-- были тремя разными «категориями», а перевод хранился отдельной строкой в
-- news_translations.badge. Ни списка категорий, ни фильтрации по ним не было.
--
-- Теперь категория — самостоятельная запись со slug, флагом активности и
-- переводом названия (механизм А: базовое имя в основной таблице, языковые
-- варианты в *_translations). Slug у категории один на все языки: это
-- идентификатор для адресов и выборок, а не текст для чтения.
--
-- Поле `news.badge` не удаляется: у него новая роль — необязательная
-- визуальная метка («Важно», «Анонс»). Перенос старых значений в категории
-- делает отдельный скрипт database/migrate_news_badges.php — там нужна
-- транслитерация slug, которой в SQL нет.

CREATE TABLE IF NOT EXISTS news_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL COMMENT 'название на основном языке',
    slug        VARCHAR(150) NOT NULL COMMENT 'один на все языки: адрес и выборки',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_news_categories_slug (slug),
    KEY idx_news_categories_order (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_category_translations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    lang        VARCHAR(8) NOT NULL,
    name        VARCHAR(150) NULL,
    UNIQUE KEY uq_news_category_translations (category_id, lang),
    CONSTRAINT fk_news_category_translations_category
        FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND COLUMN_NAME = 'category_id'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news ADD COLUMN category_id INT UNSIGNED NULL COMMENT ''категория новости'' AFTER badge',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

-- Выборка ленты по категории: без этого индекса фильтр читал бы весь набор
-- опубликованных новостей и отсеивал категорию уже после чтения.
SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND INDEX_NAME = 'idx_news_category_listing'
);
SET @asdr_sql := IF(
    @asdr_has_index = 0,
    'ALTER TABLE news ADD KEY idx_news_category_listing (category_id, status, deleted_at, published_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

-- Внешний ключ ставим последним: колонка и индекс к этому моменту уже есть.
-- ON DELETE SET NULL — удаление категории не должно уносить новости.
SET @asdr_has_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news' AND CONSTRAINT_NAME = 'fk_news_category'
);
SET @asdr_sql := IF(
    @asdr_has_fk = 0,
    'ALTER TABLE news ADD CONSTRAINT fk_news_category FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
