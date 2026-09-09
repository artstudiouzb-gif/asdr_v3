-- Проекты становятся страницами с подтипом.
--
-- Зачем: у проекта появилась нужда в конструкторе блоков, а вся его обвязка
-- (ревизии, сниппеты, пресеты, копирование перевода, сброс кэша) адресуется
-- page_id. Делать блоки полиморфными — та же работа, что и слияние, но без
-- выгоды: после слияния у проекта тот же редактор, что и у страницы.
--
-- Что остаётся прежним: адреса (/projects/{slug}), раздел «Проекты» в админке,
-- отметка «на главном», ручной порядок и блоки projects_list и cards_grid.
-- Галерея и свободные поля становятся блоками — на сайте они и так не
-- выводились, а редактировать их теперь незачем.
--
-- После миграции следов старой модели не остаётся: таблицы `projects`,
-- `project_translations`, `project_images` и `project_fields` удаляются, а весь
-- код обращается к `pages` с фильтром по типу.

-- 1. Поля, которые есть у проекта, но не было у страницы -------------------
ALTER TABLE pages
    ADD COLUMN entity_type ENUM('page', 'project') NOT NULL DEFAULT 'page'
        COMMENT 'подтип записи: обычная страница или проект' AFTER slug,
    ADD COLUMN cover_image VARCHAR(255) NULL COMMENT 'обложка (проекты)' AFTER `lead`,
    ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'показывать на главном (проекты)',
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 COMMENT 'ручной порядок (проекты)',
    ADD COLUMN legacy_project_id INT UNSIGNED NULL COMMENT 'временная карта переноса, удаляется в конце миграции';

-- Slug уникален внутри своего типа: страница «about» и проект «about» жили в
-- разных таблицах и разных адресных пространствах, так и остаётся.
ALTER TABLE pages DROP INDEX uq_pages_slug_lang;
ALTER TABLE pages ADD UNIQUE KEY uq_pages_type_slug_lang (entity_type, slug, lang);
ALTER TABLE pages ADD KEY idx_pages_projects (entity_type, status, deleted_at, is_featured, sort_order);

-- 2. Перенос самих проектов -------------------------------------------------
-- Анонс для карточки берём из описания: теги вырезаем, пробелы схлопываем,
-- режем до 300 знаков. Полное описание ниже становится блоком, поэтому здесь
-- нужен именно короткий текст, а не копия тела.
INSERT INTO pages (
    title, slug, entity_type, `lead`, cover_image, is_featured, sort_order,
    status, is_home, layout_type, lang, translation_group_id,
    created_at, updated_at, lock_version, deleted_at, legacy_project_id
)
SELECT
    src.title,
    src.slug,
    'project',
    NULLIF(TRIM(LEFT(REGEXP_REPLACE(REGEXP_REPLACE(COALESCE(src.description, ''), '<[^>]*>', ' '), '[[:space:]]+', ' '), 300)), ''),
    src.cover_image,
    src.is_featured,
    src.sort_order,
    src.status,
    0,
    'no_sidebar',
    src.lang,
    NULL,
    src.created_at,
    src.updated_at,
    src.lock_version,
    src.deleted_at,
    src.id
FROM projects src;

-- Группы переводов пересчитываем на новые id.
UPDATE pages tgt
    JOIN projects src ON src.id = tgt.legacy_project_id
    JOIN pages grp ON grp.legacy_project_id = COALESCE(NULLIF(src.translation_group_id, 0), src.id)
SET tgt.translation_group_id = grp.id
WHERE tgt.legacy_project_id IS NOT NULL;

-- 3. Описание проекта становится текстовым блоком страницы ------------------
INSERT INTO blocks (page_id, lang, type, title, data, custom_css, sort_order, is_active, created_at)
SELECT
    tgt.id,
    tgt.lang,
    'text',
    NULL,
    JSON_OBJECT('variant', 'default', 'content', src.description),
    '',
    0,
    1,
    NOW()
FROM pages tgt
    JOIN projects src ON src.id = tgt.legacy_project_id
WHERE TRIM(COALESCE(src.description, '')) <> '';

-- 4. Переводы проекта — отдельные записи своего языка ------------------------
-- Редактор работает с языками именно так: у языковой версии своя карточка в
-- разделе, связанная через translation_group_id (кнопка «Создать перевод»).
-- Slug при этом остаётся общим: он уникален в пределах пары «тип + язык».
INSERT INTO pages (
    title, slug, entity_type, `lead`, cover_image, is_featured, sort_order,
    status, is_home, layout_type, lang, translation_group_id, created_at, updated_at
)
SELECT
    pt.title,
    base.slug,
    'project',
    NULLIF(TRIM(LEFT(REGEXP_REPLACE(REGEXP_REPLACE(COALESCE(pt.description, ''), '<[^>]*>', ' '), '[[:space:]]+', ' '), 300)), ''),
    base.cover_image,
    base.is_featured,
    base.sort_order,
    base.status,
    0,
    'no_sidebar',
    pt.lang,
    COALESCE(NULLIF(base.translation_group_id, 0), base.id),
    base.created_at,
    base.updated_at
FROM project_translations pt
    JOIN pages base ON base.legacy_project_id = pt.project_id
WHERE TRIM(COALESCE(pt.title, '')) <> ''
  AND pt.lang <> base.lang
  -- Своя запись этого языка уже могла существовать (механизм «отдельная
  -- запись»): второй такой же создавать нельзя. Сверяемся с уже перенесёнными
  -- строками pages, а не со старой таблицей: там другие идентификаторы групп.
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT id, lang, entity_type, translation_group_id FROM pages) other
      WHERE other.entity_type = 'project'
        AND other.lang = pt.lang
        AND COALESCE(NULLIF(other.translation_group_id, 0), other.id)
            = COALESCE(NULLIF(base.translation_group_id, 0), base.id)
  );

-- Тело перевода — текстовый блок его собственной записи.
INSERT INTO blocks (page_id, lang, type, title, data, custom_css, sort_order, is_active, created_at)
SELECT
    tgt.id,
    tgt.lang,
    'text',
    NULL,
    JSON_OBJECT('variant', 'default', 'content', pt.description),
    '',
    0,
    1,
    NOW()
FROM project_translations pt
    JOIN pages base ON base.legacy_project_id = pt.project_id
    JOIN pages tgt ON tgt.entity_type = 'project'
                  AND tgt.lang = pt.lang
                  AND tgt.slug = base.slug
WHERE TRIM(COALESCE(pt.description, '')) <> '';

-- 5. Галерея и свободные поля становятся блоками ------------------------------
-- На публичной части они не выводились никогда, а редактировать их теперь
-- незачем: содержимое проекта собирается блоками. Данные не теряем — галерея
-- превращается в блок «Медиагалерея», пары «ключ | значение» — в блок
-- «Иконка и текст» с заголовком по группе.
UPDATE content_revisions cr JOIN pages tgt ON tgt.legacy_project_id = cr.entity_id
SET cr.entity_id = tgt.id
WHERE cr.entity_type = 'project';

INSERT INTO blocks (page_id, lang, type, title, data, custom_css, sort_order, is_active, created_at)
SELECT
    tgt.id,
    tgt.lang,
    'media_gallery',
    'Фотографии проекта',
    JSON_OBJECT(
        'title', 'Фотографии проекта',
        'source', 'manual',
        'items', JSON_ARRAYAGG(JSON_OBJECT(
            'kind', 'photo',
            'title', COALESCE(pi.caption, ''),
            'image', pi.file_path,
            'url', '',
            'meta', '',
            'text', ''
        ))
    ),
    '',
    100,
    1,
    NOW()
FROM project_images pi
    JOIN pages tgt ON tgt.legacy_project_id = pi.project_id
GROUP BY tgt.id, tgt.lang;

INSERT INTO blocks (page_id, lang, type, title, data, custom_css, sort_order, is_active, created_at)
SELECT
    tgt.id,
    tgt.lang,
    'icon_text',
    'Характеристики проекта',
    JSON_OBJECT(
        'variant', 'plain',
        'title', 'Характеристики проекта',
        'columns', 2,
        'items', JSON_ARRAY(JSON_OBJECT(
            'icon_svg', '',
            'icon_color', '',
            'rows', GROUP_CONCAT(CONCAT(pf.field_key, ' | ', COALESCE(pf.field_value, '')) ORDER BY pf.sort_order, pf.id SEPARATOR '\n')
        ))
    ),
    '',
    101,
    1,
    NOW()
FROM project_fields pf
    JOIN pages tgt ON tgt.legacy_project_id = pf.project_id
WHERE TRIM(COALESCE(pf.field_value, '')) <> ''
GROUP BY tgt.id, tgt.lang;

-- 6. Старых таблиц и совместимых представлений не остаётся -------------------
DROP TABLE project_images;
DROP TABLE project_fields;
DROP TABLE project_translations;
DROP TABLE projects;

ALTER TABLE pages DROP COLUMN legacy_project_id;
