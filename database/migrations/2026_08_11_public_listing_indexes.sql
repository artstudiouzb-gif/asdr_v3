-- Индексы для публичных выборок списков.
--
-- Публичные списки читают «опубликованные, не удалённые» и сортируют по
-- одному и тому же набору колонок. До этой миграции такие запросы шли полным
-- сканом таблицы с последующей сортировкой в памяти. На нынешних объёмах это
-- единицы миллисекунд — миграция сделана на вырост: при тысячах записей
-- добавить индекс задним числом уже дороже.
--
-- Направления сортировки смешанные («sort_order ASC, created_at DESC»), и
-- индекс их полностью не снимает: filesort остаётся, но получает на вход
-- только отобранные строки, а не всю таблицу.
--
-- Чего здесь сознательно нет: ускорения полного списка проектов. Он отдаёт
-- почти всю таблицу, и полный скан для него дешевле любого индекса —
-- оптимизатор индекс просто не возьмёт (проверено EXPLAIN). Выигрыш дают
-- запросы с LIMIT: лента новостей, медиа и блоки главной.
--
-- Идемпотентность: проверяем information_schema, а не «ADD KEY IF NOT EXISTS» —
-- этого синтаксиса нет в MySQL 8, а миграция должна применяться и там.

-- projects: Project::forHome — status='published' AND deleted_at IS NULL AND is_featured = 1.
-- is_featured в индексе обязателен: без него оптимизатор выбирает полный скан
-- (опубликованных — большинство), и индекс не работает вовсе.
SET @asdr_has_projects_listing := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'projects'
      AND INDEX_NAME = 'idx_projects_listing'
);
SET @asdr_sql := IF(
    @asdr_has_projects_listing = 0,
    'ALTER TABLE projects ADD KEY idx_projects_listing (status, deleted_at, is_featured, sort_order, created_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

-- photo_albums: PhotoAlbum::all(true)/forHome — is_published = 1 ORDER BY created_at DESC, id DESC
SET @asdr_has_albums_listing := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'photo_albums'
      AND INDEX_NAME = 'idx_albums_listing'
);
SET @asdr_sql := IF(
    @asdr_has_albums_listing = 0,
    'ALTER TABLE photo_albums ADD KEY idx_albums_listing (is_published, created_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

-- videos: Video::all(true)/forHome — is_published = 1 ORDER BY sort_order ASC, created_at DESC
SET @asdr_has_videos_listing := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'videos'
      AND INDEX_NAME = 'idx_videos_listing'
);
SET @asdr_sql := IF(
    @asdr_has_videos_listing = 0,
    'ALTER TABLE videos ADD KEY idx_videos_listing (is_published, sort_order, created_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

-- news: у ленты в условии ещё и «deleted_at IS NULL», которого не было в
-- прежнем idx_news_status_published (status, published_at) — из-за этого
-- индекс отсеивал по статусу, а мягко удалённые всё равно читались с диска.
-- Новый индекс — надмножество прежнего по префиксу, поэтому старый снимаем.
SET @asdr_has_news_listing := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news'
      AND INDEX_NAME = 'idx_news_listing'
);
SET @asdr_sql := IF(
    @asdr_has_news_listing = 0,
    'ALTER TABLE news ADD KEY idx_news_listing (status, deleted_at, published_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_news_old := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'news'
      AND INDEX_NAME = 'idx_news_status_published'
);
SET @asdr_sql := IF(
    @asdr_has_news_old > 0,
    'ALTER TABLE news DROP KEY idx_news_status_published',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql;
EXECUTE asdr_stmt;
DEALLOCATE PREPARE asdr_stmt;
