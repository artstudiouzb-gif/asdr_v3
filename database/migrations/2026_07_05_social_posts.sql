-- ---------------------------------------------------------------------------
-- Этап 13 — авто-публикация новостей в соцсети. Очередь исходящих публикаций,
-- обрабатывается CLI-воркером по Cron (по образцу mail_queue).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_posts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id     INT UNSIGNED NOT NULL,
    network     ENUM('facebook','linkedin','instagram') NOT NULL,
    status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts    INT UNSIGNED NOT NULL DEFAULT 0,
    remote_id   VARCHAR(190) NULL COMMENT 'id опубликованного поста в сети',
    last_error  VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at     DATETIME NULL,
    UNIQUE KEY uq_social_posts_news_network (news_id, network),
    KEY idx_social_posts_status (status, created_at),
    CONSTRAINT fk_social_posts_news FOREIGN KEY (news_id) REFERENCES news (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
