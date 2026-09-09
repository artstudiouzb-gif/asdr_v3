-- ---------------------------------------------------------------------------
-- Блок 11 — критическая безопасность: сброс пароля, backup-коды 2FA,
-- реестр активных сессий. Все токены/коды хранятся только в виде SHA-256
-- хешей; сравнение выполняется через hash_equals на стороне PHP.
-- ---------------------------------------------------------------------------

-- Одноразовые токены восстановления пароля (TTL задаётся в приложении, 30 мин).
CREATE TABLE IF NOT EXISTS password_resets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64)     NOT NULL COMMENT 'sha256(token)',
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_resets_hash (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Резервные коды восстановления доступа к 2FA (пул на пользователя).
CREATE TABLE IF NOT EXISTS backup_codes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    code_hash   CHAR(64)     NOT NULL COMMENT 'sha256(code)',
    used_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_backup_codes_user (user_id),
    CONSTRAINT fk_backup_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Реестр активных сессий: даёт список устройств и мгновенный серверный отзыв.
CREATE TABLE IF NOT EXISTS user_sessions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    sid_hash     CHAR(64)     NOT NULL COMMENT 'sha256(session_id)',
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_sessions_sid (sid_hash),
    KEY idx_user_sessions_user (user_id),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
