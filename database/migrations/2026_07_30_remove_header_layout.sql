-- Удаляет устаревший выбор базового макета из сохранённой конфигурации шапки.
-- Геометрия шапки теперь полностью определяется распределением элементов по зонам.
UPDATE settings
SET `value` = JSON_REMOVE(`value`, '$.layout')
WHERE `key` = 'header_config'
  AND JSON_VALID(`value`)
  AND JSON_CONTAINS_PATH(`value`, 'one', '$.layout');
