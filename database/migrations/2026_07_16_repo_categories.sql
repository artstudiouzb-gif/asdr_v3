-- Категории файлового хранилища с подкатегориями (один уровень вложенности).
-- Существующие текстовые категории repo_files переносятся в справочник,
-- строковая колонка category заменяется ссылкой category_id.
CREATE TABLE IF NOT EXISTS repo_categories (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id  BIGINT UNSIGNED NULL,
    name       VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_repo_categories_parent (parent_id),
    CONSTRAINT fk_repo_categories_parent FOREIGN KEY (parent_id) REFERENCES repo_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE repo_files
    ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER category,
    ADD KEY idx_repo_files_category_id (category_id),
    ADD CONSTRAINT fk_repo_files_category FOREIGN KEY (category_id) REFERENCES repo_categories (id) ON DELETE SET NULL;

INSERT INTO repo_categories (name)
    SELECT DISTINCT category FROM repo_files WHERE category <> '' ORDER BY category;

UPDATE repo_files f
    JOIN repo_categories c ON c.parent_id IS NULL AND c.name = f.category
    SET f.category_id = c.id
    WHERE f.category <> '';

ALTER TABLE repo_files DROP COLUMN category;
