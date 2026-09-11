-- @post-schema
-- «Преимущества» и «Карточки» — один тип блока.
--
-- Оба печатали одну и ту же карточку (`.feature-card` с тем же нутром) из
-- одних и тех же полей: иконка, заголовок, текст, ссылка. Разошлись они только
-- настройками — и расходились молча: подложку иконки у «Карточек» давно
-- сменили с синеватой на тон акцента, а у «Преимуществ» она такой и осталась.
--
-- Варианты переезжают в настройки приёмника:
--   grid, indexed → «Иконка, заголовок и текст»;
--   inline        → он же плюс «Положение иконки: в строке с заголовком»;
--   band          → «Полоса».
-- Нумерация включается всем: номер печатался во всех вариантах (правила,
-- которое его прячет, в публичном CSS не было вовсе), и выключенная настройка
-- поменяла бы вид уже собранных страниц.
UPDATE blocks
   SET type = 'cards_grid',
       data = JSON_SET(
           data,
           '$.numbering', TRUE,
           '$.variant', CASE JSON_UNQUOTE(JSON_EXTRACT(data, '$.variant'))
               WHEN 'band' THEN 'band'
               ELSE 'icon'
           END,
           '$.icon_position', CASE JSON_UNQUOTE(JSON_EXTRACT(data, '$.variant'))
               WHEN 'inline' THEN 'inline'
               ELSE 'top'
           END
       )
 WHERE type = 'advantages';

-- Библиотека шаблонов страниц хранит те же блоки отдельным JSON: без этого
-- шага сохранённый шаблон положил бы на страницу блок несуществующего типа.
UPDATE block_snippets
   SET blocks_json = REPLACE(blocks_json, '"type":"advantages"', '"type":"cards_grid"')
 WHERE blocks_json LIKE '%"type":"advantages"%';
