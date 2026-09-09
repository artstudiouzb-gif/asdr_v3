-- Восстанавливает ссылочную целостность очереди Web Push и авторов ревизий.
-- Миграция безопасна для повторного запуска после частично выполненного DDL.

DELETE q
FROM webpush_queue q
LEFT JOIN news n ON n.id = q.news_id
WHERE n.id IS NULL;

UPDATE block_revisions r
LEFT JOIN users u ON u.id = r.created_by
SET r.created_by = NULL
WHERE r.created_by IS NOT NULL
  AND u.id IS NULL;

UPDATE news child_row
LEFT JOIN news root_row ON root_row.id = child_row.translation_group_id
SET child_row.translation_group_id = child_row.id
WHERE child_row.translation_group_id IS NULL
   OR child_row.translation_group_id = 0
   OR root_row.id IS NULL;

UPDATE pages child_row
LEFT JOIN pages root_row ON root_row.id = child_row.translation_group_id
SET child_row.translation_group_id = child_row.id
WHERE child_row.translation_group_id IS NULL
   OR child_row.translation_group_id = 0
   OR root_row.id IS NULL;

UPDATE projects child_row
LEFT JOIN projects root_row ON root_row.id = child_row.translation_group_id
SET child_row.translation_group_id = child_row.id
WHERE child_row.translation_group_id IS NULL
   OR child_row.translation_group_id = 0
   OR root_row.id IS NULL;

SET @asdr_has_revision_user_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'block_revisions'
      AND COLUMN_NAME = 'created_by'
      AND SEQ_IN_INDEX = 1
);
SET @asdr_sql := IF(
    @asdr_has_revision_user_index = 0,
    'ALTER TABLE block_revisions ADD KEY idx_block_revisions_user (created_by)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_revision_user_fk := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'block_revisions'
      AND COLUMN_NAME = 'created_by'
      AND REFERENCED_TABLE_NAME = 'users'
      AND REFERENCED_COLUMN_NAME = 'id'
);
SET @asdr_sql := IF(
    @asdr_has_revision_user_fk = 0,
    'ALTER TABLE block_revisions ADD CONSTRAINT fk_block_revisions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_webpush_news_fk := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'webpush_queue'
      AND COLUMN_NAME = 'news_id'
      AND REFERENCED_TABLE_NAME = 'news'
      AND REFERENCED_COLUMN_NAME = 'id'
);
SET @asdr_sql := IF(
    @asdr_has_webpush_news_fk = 0,
    'ALTER TABLE webpush_queue ADD CONSTRAINT fk_webpush_queue_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;
