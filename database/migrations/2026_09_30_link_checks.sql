-- Проверка ссылок в контенте: последний известный ответ по каждому адресу.
-- Данные производные — их пересобирает проход LinkChecker, адреса, которых
-- в контенте больше нет, он удаляет. fail_streak нужен внешним ссылкам:
-- чужой сервер, не ответивший один раз, ещё не повод звать его битым.
CREATE TABLE IF NOT EXISTS link_checks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url_hash        CHAR(40)     NOT NULL COMMENT 'sha1 адреса: сам адрес бывает длиннее индекса',
    url             TEXT         NOT NULL,
    is_internal     TINYINT(1)   NOT NULL DEFAULT 0,
    state           VARCHAR(16)  NOT NULL DEFAULT 'unchecked' COMMENT 'ok|broken|unreachable|blocked|unchecked',
    status          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error           VARCHAR(255) NULL,
    fail_streak     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_failed_at DATETIME     NULL,
    checked_at      DATETIME     NULL,
    UNIQUE KEY uq_link_checks_hash (url_hash),
    KEY idx_link_checks_state (state, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
