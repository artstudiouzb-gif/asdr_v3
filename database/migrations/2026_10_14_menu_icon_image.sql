-- Своя иконка пункта меню: адрес картинки (SVG/PNG) из медиатеки. Каталог
-- Tabler не знает гербов и логотипов программ, а их просят в меню.
-- Заполненное поле главнее icon_svg.
ALTER TABLE menu_items
    ADD COLUMN icon_image VARCHAR(500) NULL AFTER icon_svg;
