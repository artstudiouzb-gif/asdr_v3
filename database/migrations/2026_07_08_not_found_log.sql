-- 404-трекер: входящие пути, вызвавшие 404 (особенно с внешним referer) —
-- список в админке для быстрого превращения в 301-редиректы после переезда.
CREATE TABLE IF NOT EXISTS not_found_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path         VARCHAR(255) NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 1,
    last_referer VARCHAR(500) NULL,
    first_hit_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_hit_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_not_found_path (path),
    KEY idx_not_found_hits (hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
