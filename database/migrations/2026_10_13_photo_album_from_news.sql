-- Альбом, собранный из новости-галереи, помнит свою новость: по ней альбом
-- ведёт ссылку «Читать новость», а повторная сборка обновляет тот же альбом,
-- а не создаёт второй. NULL — альбом создан вручную.
ALTER TABLE photo_albums
    ADD COLUMN news_id INT UNSIGNED NULL AFTER is_featured,
    ADD UNIQUE KEY uniq_albums_news (news_id);
