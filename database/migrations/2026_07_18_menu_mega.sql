-- Мега-меню: у пункта верхнего уровня подменю раскладывается в несколько
-- колонок. 0 — обычная выпадашка в один столбец (поведение по умолчанию).
ALTER TABLE menu_items
    ADD COLUMN mega_columns TINYINT NOT NULL DEFAULT 0
        COMMENT '0 — обычное подменю, 2..4 — мега-меню в N колонок'
        AFTER parent_id;
