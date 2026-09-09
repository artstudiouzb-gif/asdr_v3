-- @post-schema
-- Палитра «Дизайна» сведена с токенами темы (см. DesignSettings::semanticColors).
--
-- Одной правки умолчаний в коде мало: раздел «Дизайн» сохраняет все свои поля
-- разом, поэтому на сайте, где эту форму хоть раз открывали и сохраняли, в
-- settings лежат прежние нейтральные серые — и новые умолчания до страницы не
-- доходят. Переписываем ровно те строки, значение которых совпадает с прежним
-- умолчанием: осознанно выбранный редактором цвет так не выглядит, а всё
-- остальное остаётся нетронутым.
--
-- На свежей установке этих строк нет вовсе, поэтому миграция здесь ничего не
-- делает — она про уже работающие сайты, а не про схему.
UPDATE settings SET `value` = '#0f172a'
 WHERE `key` = 'design_semantic_text_main' AND LOWER(`value`) = '#1a1a1a';
UPDATE settings SET `value` = '#475569'
 WHERE `key` = 'design_semantic_text_muted' AND LOWER(`value`) = '#666666';
UPDATE settings SET `value` = '#f8fafc'
 WHERE `key` = 'design_semantic_bg_primary' AND LOWER(`value`) = '#f4f6f8';
UPDATE settings SET `value` = '#e2e8f0'
 WHERE `key` = 'design_semantic_border_color' AND LOWER(`value`) = '#e6ebf0';
