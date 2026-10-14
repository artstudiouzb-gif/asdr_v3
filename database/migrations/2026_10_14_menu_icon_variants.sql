-- Размер иконки пункта меню и светлый вариант своей иконки для прозрачной
-- шапки и тёмной темы (как светлый логотип).
ALTER TABLE menu_items
    ADD COLUMN icon_image_light VARCHAR(500) NULL AFTER icon_image,
    ADD COLUMN icon_size SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER icon_image_light;
