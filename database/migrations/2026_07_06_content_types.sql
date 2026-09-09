-- ---------------------------------------------------------------------------
-- Этап 16.4 — конструктор произвольных типов контента (задачи 131/132).
-- Значения полей хранятся в JSON-колонке content_entries.data (совместимо с
-- мерджем дефолтов блоков). Мультиязычность — по образцу *_translations.
-- Существующие news/projects/team не затрагиваются.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_types (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug             VARCHAR(60)  NOT NULL,
    name             VARCHAR(190) NOT NULL,
    has_translations TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_content_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_type_fields (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(60)  NOT NULL COMMENT 'машинное имя (латиница)',
    label       VARCHAR(190) NOT NULL,
    field_type  ENUM('text','textarea','number','date','file','relation') NOT NULL DEFAULT 'text',
    required    TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  INT          NOT NULL DEFAULT 0,
    options     LONGTEXT     NULL COMMENT 'JSON: напр. relation_type для relation',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_content_type_fields_type (type_id, sort_order),
    CONSTRAINT fk_content_type_fields_type FOREIGN KEY (type_id) REFERENCES content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_entries (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    slug        VARCHAR(255) NOT NULL,
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    data        LONGTEXT     NOT NULL COMMENT 'JSON значений полей',
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME     NULL,
    UNIQUE KEY uq_content_entries_slug (type_id, slug),
    KEY idx_content_entries_type (type_id, status),
    CONSTRAINT fk_content_entries_type FOREIGN KEY (type_id) REFERENCES content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_entry_translations (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id    BIGINT UNSIGNED NOT NULL,
    lang        VARCHAR(8)   NOT NULL,
    title       VARCHAR(255) NULL,
    data        LONGTEXT     NULL COMMENT 'JSON переведённых значений',
    UNIQUE KEY uq_content_entry_translations (entry_id, lang),
    CONSTRAINT fk_content_entry_translations_entry FOREIGN KEY (entry_id) REFERENCES content_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
