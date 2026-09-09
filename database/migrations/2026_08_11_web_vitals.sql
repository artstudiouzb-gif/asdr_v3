-- Core Web Vitals с реальных посетителей.
--
-- Хранится только то, что нужно для 75-го перцентиля: метрика, значение и
-- крупные срезы. Ни IP, ни User-Agent, ни идентификатора посетителя здесь
-- нет — иначе на госсайте это стало бы персональными данными, а у нас ещё и
-- ломало бы общий кеш (см. историю с Vary: Cookie).
CREATE TABLE IF NOT EXISTS web_vitals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    metric VARCHAR(8) NOT NULL,
    value DOUBLE NOT NULL,
    rating VARCHAR(16) NOT NULL DEFAULT '',
    page_kind VARCHAR(24) NOT NULL DEFAULT 'other',
    device VARCHAR(8) NOT NULL DEFAULT 'desktop',
    lang VARCHAR(8) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_web_vitals_metric (metric, created_at),
    KEY idx_web_vitals_slice (metric, page_kind, device, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
