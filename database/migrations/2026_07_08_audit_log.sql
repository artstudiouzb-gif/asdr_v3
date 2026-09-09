-- Журнал действий администраторов: центральная запись всех изменяющих
-- запросов панели (/admin, POST/PUT/DELETE). Тело запроса не сохраняется —
-- только кто, что (метод + путь), когда и с какого IP.
CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    username   VARCHAR(100) NOT NULL DEFAULT '',
    method     VARCHAR(8) NOT NULL DEFAULT 'POST',
    path       VARCHAR(255) NOT NULL,
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
