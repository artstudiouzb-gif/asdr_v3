-- ---------------------------------------------------------------------------
-- Публичный фронтенд пользовательских типов контента + стартовые типы для
-- государственного сайта (Документы / Вакансии / Тендеры).
--   * content_type_fields: добавляем тип поля 'image' (поле уже поддержано в
--     UI, но enum его не содержал — скрытая ошибка);
--   * content_types: is_public (показывать ли раздел на сайте) и description;
--   * сеем три стартовых типа с полями (полностью редактируемы в админке).
-- ---------------------------------------------------------------------------
ALTER TABLE content_type_fields
    MODIFY field_type ENUM('text','textarea','number','date','image','file','relation') NOT NULL DEFAULT 'text';

ALTER TABLE content_types
    ADD COLUMN is_public   TINYINT(1)   NOT NULL DEFAULT 1 AFTER has_translations,
    ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT '' AFTER name;

-- --- Стартовые типы государственного сайта -------------------------------
INSERT IGNORE INTO content_types (slug, name, description, has_translations, is_public, created_at) VALUES
    ('documenty', 'Документы', 'Официальные документы, приказы и постановления', 1, 1, NOW()),
    ('vakansii',  'Вакансии',  'Открытые вакансии организации', 1, 1, NOW()),
    ('tendery',   'Тендеры',   'Актуальные тендеры и закупки', 1, 1, NOW());

INSERT INTO content_type_fields (type_id, name, label, field_type, required, sort_order, created_at)
SELECT t.id, f.name, f.label, f.field_type, f.required, f.sort_order, NOW()
FROM content_types t
JOIN (
    SELECT 'documenty' AS slug, 'doc_number' AS name, 'Номер документа' AS label, 'text' AS field_type, 0 AS required, 0 AS sort_order
    UNION ALL SELECT 'documenty', 'doc_date',  'Дата',              'date',     0, 1
    UNION ALL SELECT 'documenty', 'category',  'Категория',         'text',     0, 2
    UNION ALL SELECT 'documenty', 'summary',   'Краткое описание',  'textarea', 0, 3
    UNION ALL SELECT 'documenty', 'file',      'Файл документа',    'file',     1, 4
    UNION ALL SELECT 'vakansii',  'department','Отдел',             'text',     0, 0
    UNION ALL SELECT 'vakansii',  'salary',    'Зарплата',          'text',     0, 1
    UNION ALL SELECT 'vakansii',  'deadline',  'Приём заявок до',   'date',     0, 2
    UNION ALL SELECT 'vakansii',  'requirements','Требования',      'textarea', 0, 3
    UNION ALL SELECT 'vakansii',  'duties',    'Обязанности',       'textarea', 0, 4
    UNION ALL SELECT 'tendery',   'tender_number','Номер тендера',  'text',     0, 0
    UNION ALL SELECT 'tendery',   'budget',    'Бюджет',            'text',     0, 1
    UNION ALL SELECT 'tendery',   'start_date','Дата публикации',   'date',     0, 2
    UNION ALL SELECT 'tendery',   'deadline',  'Приём заявок до',   'date',     0, 3
    UNION ALL SELECT 'tendery',   'summary',   'Описание',          'textarea', 0, 4
    UNION ALL SELECT 'tendery',   'file',      'Тендерная документация', 'file', 0, 5
) f ON f.slug = t.slug
WHERE NOT EXISTS (
    SELECT 1 FROM content_type_fields x WHERE x.type_id = t.id
);
