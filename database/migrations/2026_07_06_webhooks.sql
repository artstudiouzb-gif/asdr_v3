-- ---------------------------------------------------------------------------
-- Этап 16.2 — исходящие вебхуки (задача 136). Единственный универсальный
-- механизм интеграции с внешними системами (архитектура без плагинов).
-- Доставка асинхронна через очередь + CLI-воркер, с HMAC-подписью и ретраями.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhooks (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type  VARCHAR(60)  NOT NULL COMMENT 'form.submitted | news.published | ...',
    url         VARCHAR(500) NOT NULL,
    secret      VARCHAR(190) NULL COMMENT 'ключ HMAC-подписи тела запроса',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhooks_event (event_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id    BIGINT UNSIGNED NOT NULL,
    event_type    VARCHAR(60)  NOT NULL,
    payload_json  LONGTEXT     NOT NULL,
    status        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    response_code INT          NULL,
    last_error    VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at       DATETIME     NULL,
    KEY idx_webhook_deliveries_status (status, created_at),
    KEY idx_webhook_deliveries_hook (webhook_id, created_at),
    CONSTRAINT fk_webhook_deliveries_hook FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
