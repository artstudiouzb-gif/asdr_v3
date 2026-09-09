-- Добавление позиции бейджа/плашки (right, left, center) для пунктов меню
ALTER TABLE menu_items ADD COLUMN badge_pos VARCHAR(20) NOT NULL DEFAULT 'right' AFTER badge_color;
