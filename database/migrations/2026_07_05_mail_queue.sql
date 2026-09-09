-- Миграция: очередь исходящих писем (обрабатывается CLI-воркером по Cron).

CREATE TABLE IF NOT EXISTS mail_queue (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email        VARCHAR(190) NOT NULL,
    to_name         VARCHAR(190) NULL,
    subject         VARCHAR(255) NOT NULL,
    body            LONGTEXT NOT NULL,
    status          ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts        INT UNSIGNED NOT NULL DEFAULT 0,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME NULL,
    KEY idx_mail_queue_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
