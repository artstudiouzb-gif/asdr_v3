-- «Скрыть хлебные крошки» — свой флажок. Прежде крошки уходили вместе со
-- скрытым заголовком; страницы, где заголовок уже скрыт, сохраняют свой вид.
ALTER TABLE pages
    ADD COLUMN hide_crumbs TINYINT(1) NOT NULL DEFAULT 0 AFTER hide_title;
UPDATE pages SET hide_crumbs = 1 WHERE hide_title = 1;
