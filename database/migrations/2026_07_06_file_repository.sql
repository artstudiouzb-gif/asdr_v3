-- ---------------------------------------------------------------------------
-- Защищённое файловое хранилище (репозиторий) с собственной авторизацией,
-- независимой от админ-панели.
--   repo_users  — учётные записи портала (создаёт супер-администратор),
--                 логин + пароль + опциональная 2FA (TOTP).
--   repo_files  — общий пул файлов (все авторизованные пользователи видят
--                 все файлы; загружает только администратор). Файлы лежат в
--                 storage/protected_uploads/repo/ (вне webroot), отдаются
--                 стримом только после проверки сессии портала.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS repo_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(60)  NOT NULL,
    full_name       VARCHAR(190) NOT NULL DEFAULT '',
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    totp_secret     VARCHAR(64)  NULL,
    totp_enabled    TINYINT(1)   NOT NULL DEFAULT 0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_repo_users_username (username),
    UNIQUE KEY uq_repo_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repo_files (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    category        VARCHAR(120) NOT NULL DEFAULT '',
    stored_name     VARCHAR(255) NOT NULL COMMENT 'случайное имя на диске (без user input)',
    original_name   VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(120) NOT NULL,
    size            BIGINT UNSIGNED NOT NULL,
    download_count  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by     INT UNSIGNED NULL COMMENT 'id администратора-загрузчика (users.id)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_repo_files_category (category),
    KEY idx_repo_files_created (created_at),
    CONSTRAINT fk_repo_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
