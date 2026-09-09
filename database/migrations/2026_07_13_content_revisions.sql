-- Полная история версий страниц, новостей и проектов.
CREATE TABLE IF NOT EXISTS content_revisions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     VARCHAR(20) NOT NULL,
    entity_id       INT UNSIGNED NOT NULL,
    snapshot        LONGTEXT NOT NULL,
    snapshot_hash   CHAR(64) NOT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_content_revisions_entity (entity_type, entity_id, id),
    KEY idx_content_revisions_created (created_at),
    CONSTRAINT fk_content_revisions_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
