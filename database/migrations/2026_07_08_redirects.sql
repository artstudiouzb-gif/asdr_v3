-- Менеджер 301/302-редиректов: переезд со старого сайта без потери ссылок.
-- Совпадение по пути (без query-строки); проверяется до маршрутизации.
CREATE TABLE IF NOT EXISTS redirects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_path   VARCHAR(255) NOT NULL,
    to_url      VARCHAR(500) NOT NULL,
    code        SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    hits        INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_redirects_from (from_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
