-- «Скрыть заголовок страницы»: шапка страницы (название и вводный текст) не
-- показывается над содержимым, h1 остаётся визуально скрытым. Не зависит от
-- прозрачной шапки.
ALTER TABLE pages
    ADD COLUMN hide_title TINYINT(1) NOT NULL DEFAULT 0 AFTER transparent_header;
