-- Webpush: подписки браузеров и очередь уведомлений о новостях.
CREATE TABLE IF NOT EXISTS webpush_subscriptions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint      VARCHAR(1000) NOT NULL,
    endpoint_hash CHAR(40) NOT NULL COMMENT 'sha1(endpoint) для уникального индекса',
    p256dh        VARCHAR(255) NOT NULL,
    auth          VARCHAR(64) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_webpush_endpoint (endpoint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpush_queue (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id    INT UNSIGNED NOT NULL,
    status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts   INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME NULL,
    UNIQUE KEY uniq_webpush_queue_news (news_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
