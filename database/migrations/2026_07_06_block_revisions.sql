-- ---------------------------------------------------------------------------
-- Группа 5.1 — история версий блока. Перед каждой перезаписью блока его
-- текущее состояние (title/data/custom_css) снимается в block_revisions.
-- Хранятся последние 20 ревизий на блок; восстановление тоже создаёт ревизию.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS block_revisions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    block_id    INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NULL,
    data        JSON NOT NULL,
    custom_css  TEXT NULL,
    created_by  INT UNSIGNED NULL COMMENT 'автор изменения (users.id), NULL если неизвестен',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_block_revisions_block (block_id, id),
    CONSTRAINT fk_block_revisions_block FOREIGN KEY (block_id) REFERENCES blocks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
