-- Таблица отслеживания просмотров новостей по дням
CREATE TABLE IF NOT EXISTS news_views (
    news_id INT UNSIGNED NOT NULL,
    view_date DATE NOT NULL,
    views_count INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (news_id, view_date),
    KEY idx_news_views_date (view_date, views_count),
    CONSTRAINT fk_news_views_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
