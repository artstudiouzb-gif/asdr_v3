-- Подпись и автор/источник у фотографий: галерея новостей получает оба поля
-- (там была только служебная alt), у альбомных снимков подпись уже есть —
-- добавляется автор. Шаги идемпотентны: колонка добавляется только если её нет.

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_images'
      AND COLUMN_NAME = 'caption'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_images ADD COLUMN caption VARCHAR(255) NULL AFTER alt_text',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news_images'
      AND COLUMN_NAME = 'credit'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE news_images ADD COLUMN credit VARCHAR(255) NULL AFTER caption',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'photo_album_images'
      AND COLUMN_NAME = 'credit'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE photo_album_images ADD COLUMN credit VARCHAR(255) NOT NULL DEFAULT \'\' AFTER caption',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
