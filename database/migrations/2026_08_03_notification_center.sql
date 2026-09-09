CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(40) NOT NULL DEFAULT 'system',
    severity VARCHAR(16) NOT NULL DEFAULT 'info',
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    dedupe_key VARCHAR(190) NULL,
    requires_ack TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notifications_dedupe (dedupe_key),
    KEY idx_notifications_created (created_at),
    KEY idx_notifications_category_severity (category, severity),
    CONSTRAINT fk_notifications_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at DATETIME NULL,
    acknowledged_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_recipient (notification_id, user_id),
    KEY idx_notification_recipient_unread (user_id, read_at, dismissed_at),
    KEY idx_notification_recipient_ack (user_id, acknowledged_at),
    CONSTRAINT fk_notification_recipients_notification
        FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_recipients_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    email_enabled TINYINT(1) NOT NULL DEFAULT 0,
    telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
    minimum_severity VARCHAR(16) NOT NULL DEFAULT 'warning',
    quiet_start TIME NULL,
    quiet_end TIME NULL,
    digest_mode VARCHAR(16) NOT NULL DEFAULT 'none',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(16) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    locked_until DATETIME NULL,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_delivery_idempotency (idempotency_key),
    KEY idx_notification_delivery_queue (status, next_attempt_at, locked_until, created_at),
    CONSTRAINT fk_notification_deliveries_recipient
        FOREIGN KEY (recipient_id) REFERENCES notification_recipients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
