-- Пункты меню: ключ локальной иконки Tabler и признак «разделитель».
-- icon_svg  — историческое имя столбца; хранит только ключ (например home);
-- is_divider — пункт-разделитель (визуальная черта/зазор без ссылки).
ALTER TABLE menu_items
    ADD COLUMN icon_svg   TEXT NULL COMMENT 'ключ локальной иконки Tabler' AFTER title,
    ADD COLUMN is_divider TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'пункт-разделитель меню' AFTER icon_svg;
