-- Блокировка учётной записи без удаления (FUNC-5): удаление обнуляет
-- авторство новостей (fk_news_author ON DELETE SET NULL) и стирает след в
-- журналах, а заблокированный пользователь просто не может войти.
ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
