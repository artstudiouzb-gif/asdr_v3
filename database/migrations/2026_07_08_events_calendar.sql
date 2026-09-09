-- Календарь мероприятий: публичный тип контента «Мероприятия» с датой,
-- временем и местом. Страница /calendar выводит месячную сетку.
INSERT IGNORE INTO content_types (slug, name, description, has_translations, is_public, created_at) VALUES
    ('meropriyatiya', 'Мероприятия', 'Календарь событий и мероприятий организации', 1, 1, NOW());

INSERT INTO content_type_fields (type_id, name, label, field_type, required, sort_order, created_at)
SELECT t.id, f.name, f.label, f.field_type, f.required, f.sort_order, NOW()
FROM content_types t
JOIN (
    SELECT 'meropriyatiya' AS slug, 'event_date' AS name, 'Дата проведения' AS label, 'date' AS field_type, 1 AS required, 0 AS sort_order
    UNION ALL SELECT 'meropriyatiya', 'event_time', 'Время',            'text',     0, 1
    UNION ALL SELECT 'meropriyatiya', 'location',   'Место проведения', 'text',     0, 2
    UNION ALL SELECT 'meropriyatiya', 'summary',    'Описание',         'textarea', 0, 3
) f ON f.slug = t.slug
WHERE NOT EXISTS (SELECT 1 FROM content_type_fields x WHERE x.type_id = t.id);
