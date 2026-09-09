-- Добавление полей бейджей/плашек (АКТУАЛЬНО!, NEW, HOT) для пунктов меню
ALTER TABLE menu_items ADD COLUMN badge_text VARCHAR(100) NULL AFTER icon_svg;
ALTER TABLE menu_items ADD COLUMN badge_color VARCHAR(30) NULL DEFAULT 'red' AFTER badge_text;
