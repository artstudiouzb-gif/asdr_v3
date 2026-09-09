ALTER TABLE news ADD COLUMN timeline_json JSON NULL AFTER event_meta;

CREATE TABLE IF NOT EXISTS news_polls (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id     INT UNSIGNED NOT NULL,
    question    VARCHAR(255) NOT NULL,
    options_json JSON NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_news_polls_news (news_id),
    CONSTRAINT fk_news_polls_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_poll_votes (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    poll_id      INT UNSIGNED NOT NULL,
    option_index INT UNSIGNED NOT NULL,
    voter_hash   VARCHAR(64) NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_news_poll_voter (poll_id, voter_hash),
    KEY idx_news_poll_votes_poll (poll_id, option_index),
    CONSTRAINT fk_news_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES news_polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

