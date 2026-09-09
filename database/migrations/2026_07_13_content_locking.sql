-- Монотонная версия предотвращает lost update даже при сохранениях в одну секунду.
ALTER TABLE pages ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;
ALTER TABLE news ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;
ALTER TABLE projects ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;
