-- Нормализует корни групп после обновления со старой схемы.
-- Языковые записи с уже заданной группой не изменяются.

UPDATE news
SET translation_group_id = id
WHERE translation_group_id IS NULL OR translation_group_id = 0;

UPDATE pages
SET translation_group_id = id
WHERE translation_group_id IS NULL OR translation_group_id = 0;

UPDATE projects
SET translation_group_id = id
WHERE translation_group_id IS NULL OR translation_group_id = 0;
