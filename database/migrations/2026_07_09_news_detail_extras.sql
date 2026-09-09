-- Детальная страница новости по утверждённому эскизу: бейдж категории,
-- пресс-релиз, ключевые тезисы, карточка «О мероприятии», документы,
-- подпись источника и счётчик просмотров. Все поля управляются из админки.
ALTER TABLE news
    ADD COLUMN badge VARCHAR(100) NULL AFTER excerpt,
    ADD COLUMN press_release_url VARCHAR(255) NULL AFTER video_url,
    ADD COLUMN key_points TEXT NULL AFTER press_release_url,
    ADD COLUMN event_meta TEXT NULL AFTER key_points,
    ADD COLUMN docs TEXT NULL AFTER event_meta,
    ADD COLUMN source_note VARCHAR(255) NULL AFTER docs,
    ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0 AFTER source_note;
