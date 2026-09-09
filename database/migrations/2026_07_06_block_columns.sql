-- ---------------------------------------------------------------------------
-- Группа 4.1 — блок «Columns» с вложенными блоками. Плоский список блоков
-- получает вложенность: parent_block_id (родительский блок columns) и
-- column_index (номер колонки). Обычные блоки имеют parent_block_id = NULL.
-- ---------------------------------------------------------------------------
ALTER TABLE blocks
    ADD COLUMN parent_block_id INT UNSIGNED NULL AFTER page_id,
    ADD COLUMN column_index    INT NOT NULL DEFAULT 0 AFTER parent_block_id,
    ADD KEY idx_blocks_parent (parent_block_id, column_index, sort_order),
    ADD CONSTRAINT fk_blocks_parent FOREIGN KEY (parent_block_id) REFERENCES blocks(id) ON DELETE CASCADE;
