-- Журнал ошибок сайта: перехваченные исключения и фаталы с понятным
-- объяснением для администратора. Хранение — максимум 7 дней (авточистка
-- при записи и просмотре) либо ручная очистка из панели.
CREATE TABLE IF NOT EXISTS error_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level      VARCHAR(10) NOT NULL DEFAULT 'ERROR',
    human      VARCHAR(500) NOT NULL DEFAULT '',
    message    TEXT NOT NULL,
    file       VARCHAR(500) NOT NULL DEFAULT '',
    line       INT UNSIGNED NOT NULL DEFAULT 0,
    url        VARCHAR(500) NOT NULL DEFAULT '',
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_error_created (created_at),
    KEY idx_error_level (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
