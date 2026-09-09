-- История проверок индексации: без неё нельзя ответить на вопрос «стало хуже
-- или так было всегда», а именно он возникает первым.
CREATE TABLE IF NOT EXISTS seo_audits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    errors      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    warnings    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    findings    LONGTEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_seo_audits_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
