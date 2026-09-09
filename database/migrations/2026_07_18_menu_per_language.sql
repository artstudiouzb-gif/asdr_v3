-- Разделение общего меню («Все языки», lang = '') на отдельные меню по каждому
-- активному языку. Каждый пункт дублируется в каждый активный язык с сохранением
-- порядка и одноуровневой вложенности; исходные общие пункты удаляются.
-- На чистой установке пунктов lang = '' нет — миграция ничего не делает.

ALTER TABLE menu_items ADD COLUMN _src_id INT UNSIGNED NULL;

-- Копия каждого общего пункта для каждого активного языка (parent_id пока NULL,
-- в _src_id — id исходного пункта, чтобы затем восстановить вложенность).
INSERT INTO menu_items (lang, title, icon_svg, is_divider, url_type, url_value, parent_id, sort_order, is_active, created_at, _src_id)
SELECT l.code, m.title, m.icon_svg, m.is_divider, m.url_type, m.url_value, NULL, m.sort_order, m.is_active, NOW(), m.id
FROM menu_items m
CROSS JOIN languages l
WHERE m.lang = '' AND l.is_active = 1;

-- Восстанавливаем вложенность внутри каждого языка: родитель копии = копия
-- исходного родителя того же языка.
UPDATE menu_items c
JOIN menu_items o ON o.id = c._src_id
JOIN menu_items p ON p._src_id = o.parent_id AND p.lang = c.lang
SET c.parent_id = p.id
WHERE c._src_id IS NOT NULL AND o.parent_id IS NOT NULL;

-- Удаляем исходные общие пункты и временную колонку.
DELETE FROM menu_items WHERE lang = '';
ALTER TABLE menu_items DROP COLUMN _src_id;
