-- ---------------------------------------------------------------------------
-- Этап 16.1 — библиотека шаблонов блоков (сниппетов, задача 133).
-- Сохранённый набор блоков страницы (data + custom_css) для повторного
-- применения. При вставке блоки получают новые id (custom_css скоупится по
-- #block-{id} на рендере, поэтому конфликтов не возникает).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS block_snippets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(190) NOT NULL,
    blocks_json LONGTEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
