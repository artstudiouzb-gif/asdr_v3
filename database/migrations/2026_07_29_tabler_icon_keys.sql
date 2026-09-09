-- icon_svg сохраняет историческое имя для совместимости API, но после
-- перехода на Tabler Icons содержит только короткий ключ локальной иконки.
UPDATE menu_items
SET icon_svg = NULL
WHERE icon_svg IS NOT NULL
  AND (
      CHAR_LENGTH(icon_svg) > 80
      OR LOWER(icon_svg) NOT REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'
  );

ALTER TABLE menu_items
    MODIFY COLUMN icon_svg TEXT NULL COMMENT 'ключ локальной иконки Tabler';
