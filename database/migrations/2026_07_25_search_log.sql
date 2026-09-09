-- Логирование внутренних поисковых запросов для статистики админки
CREATE TABLE IF NOT EXISTS search_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(190) NOT NULL,
    results_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_search_log_created (created_at),
    KEY idx_search_log_query (query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
