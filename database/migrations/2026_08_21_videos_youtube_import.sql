-- Автозагрузка роликов с YouTube-канала: у видео появляется происхождение.
--
-- Импорт берёт с канала название, описание, ссылку и кадр-превью (само видео
-- остаётся на YouTube). Ключ повторного прохода — youtube_id: по нему запись
-- узнаётся и не создаётся второй раз. published_at — дата ролика на канале,
-- по ней строится порядок списка (created_at у импортированных записей это
-- дата импорта, а не дата видео).
ALTER TABLE videos
    ADD COLUMN youtube_id   VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'id ролика YouTube — ключ повторного импорта' AFTER video_url,
    ADD COLUMN source       VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'происхождение: пусто — вручную, youtube — импорт' AFTER youtube_id,
    ADD COLUMN published_at DATETIME NULL COMMENT 'дата публикации ролика на канале' AFTER source,
    ADD INDEX idx_videos_youtube (youtube_id),
    ADD INDEX idx_videos_dates (is_published, published_at);
