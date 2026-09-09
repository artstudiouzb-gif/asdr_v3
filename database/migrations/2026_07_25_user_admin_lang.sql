-- Добавление предпочитаемого языка админки для каждого пользователя
ALTER TABLE users ADD COLUMN admin_lang VARCHAR(8) NULL DEFAULT NULL COMMENT 'предпочитаемый язык интерфейса админки (ru, uz, en)';
