-- @post-schema
-- Пункты меню, ведущие на проект, потеряли префикс адреса.
--
-- Публичный адрес проекта — `/projects/<slug>`, и пункт меню хранит его
-- целиком. Но синхронизация меню между языками записывала цели один слаг
-- (`MenuItem::synchronizedRow`), поэтому у неосновного языка пункт указывал на
-- `<slug>`. Такое значение не разрешается ни во что: цель ищется среди
-- страниц, а страницы с этим адресом нет — пункт молча пропадал из шапки.
--
-- Чиним ровно такие строки: значение без префикса, страницы с этим слагом нет,
-- а проект с ним есть. Пункт, ведущий на настоящую страницу, под условие не
-- подходит и остаётся нетронутым.
UPDATE menu_items mi
   SET mi.url_value = CONCAT('projects/', mi.url_value)
 WHERE mi.url_type = 'page'
   AND mi.url_value IS NOT NULL
   AND mi.url_value <> ''
   AND mi.url_value NOT LIKE 'projects/%'
   AND NOT EXISTS (
        SELECT 1 FROM pages p
         WHERE p.slug = mi.url_value AND p.entity_type = 'page' AND p.deleted_at IS NULL
   )
   AND EXISTS (
        SELECT 1 FROM pages p
         WHERE p.slug = mi.url_value AND p.entity_type = 'project' AND p.deleted_at IS NULL
   );
