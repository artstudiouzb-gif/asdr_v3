-- Необязательный баннер мероприятия: медиабиблиотека в админке,
-- изображение карточки/детальной страницы и Open Graph на фронтенде.

UPDATE content_type_fields f
INNER JOIN content_types t ON t.id = f.type_id
SET f.sort_order = 4
WHERE t.slug = 'meropriyatiya'
  AND f.name = 'summary'
  AND f.sort_order < 4;

INSERT INTO content_type_fields
    (type_id, name, label, field_type, required, sort_order, created_at)
SELECT t.id, 'banner_image', 'Баннер мероприятия', 'image', 0, 3, NOW()
FROM content_types t
WHERE t.slug = 'meropriyatiya'
  AND NOT EXISTS (
      SELECT 1
      FROM content_type_fields existing_field
      WHERE existing_field.type_id = t.id
        AND existing_field.name = 'banner_image'
  );
