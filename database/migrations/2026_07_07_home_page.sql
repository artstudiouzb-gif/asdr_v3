-- ---------------------------------------------------------------------------
-- Стартовая главная страница (фронтенд). Раньше свежая установка отдавала 404
-- на «/», пока админ вручную не создавал страницу с флагом is_home. Теперь
-- сеем готовую композицию: hero (CTA) + быстрые ссылки на разделы (колонки с
-- CTA) + лента последних новостей. Полностью редактируется в конструкторе.
-- Идемпотентно: не трогает существующую главную и не дублирует блоки.
-- ---------------------------------------------------------------------------
INSERT INTO pages (title, slug, status, is_home, layout_type, created_at)
SELECT 'Главная', 'home', 'published', 1, 'no_sidebar', NOW()
WHERE NOT EXISTS (SELECT 1 FROM pages WHERE is_home = 1);

SET @home := (SELECT id FROM pages WHERE slug = 'home' AND is_home = 1 ORDER BY id ASC LIMIT 1);
SET @seed := IF(@home IS NOT NULL AND (SELECT COUNT(*) FROM blocks WHERE page_id = @home) = 0, 1, 0);

INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, 'ru', 'cta', 'Hero',
    '{"title":"Официальный сайт организации","text":"Актуальная информация, документы, новости и услуги в одном месте.","button_text":"Последние новости","button_url":"/news","_spacing":"max"}',
    1, 1, NOW()
FROM DUAL WHERE @seed = 1;

INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, 'ru', 'columns', 'Быстрые ссылки',
    '{"columns":3,"gap":"medium","_spacing":"premium"}',
    2, 1, NOW()
FROM DUAL WHERE @seed = 1;
SET @cols := IF(@seed = 1, LAST_INSERT_ID(), NULL);

INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, @cols, 0, 'ru', 'cta', NULL,
    '{"title":"Документы","text":"Приказы, постановления и официальные документы.","button_text":"Открыть раздел","button_url":"/catalog/documenty"}',
    1, 1, NOW()
FROM DUAL WHERE @seed = 1 AND @cols IS NOT NULL;

INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, @cols, 1, 'ru', 'cta', NULL,
    '{"title":"Вакансии","text":"Открытые вакансии и условия работы.","button_text":"Открыть раздел","button_url":"/catalog/vakansii"}',
    1, 1, NOW()
FROM DUAL WHERE @seed = 1 AND @cols IS NOT NULL;

INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, @cols, 2, 'ru', 'cta', NULL,
    '{"title":"Тендеры","text":"Актуальные тендеры и закупки.","button_text":"Открыть раздел","button_url":"/catalog/tendery"}',
    1, 1, NOW()
FROM DUAL WHERE @seed = 1 AND @cols IS NOT NULL;

INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, 'ru', 'news_latest', 'Последние новости',
    '{"title":"Последние новости","limit":3,"_spacing":"premium"}',
    3, 1, NOW()
FROM DUAL WHERE @seed = 1;
