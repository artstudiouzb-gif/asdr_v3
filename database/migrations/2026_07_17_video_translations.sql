-- Переводы видео: заголовок и описание на неосновных языках.
CREATE TABLE IF NOT EXISTS video_translations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id     INT UNSIGNED NOT NULL,
    lang         VARCHAR(8) NOT NULL,
    title        VARCHAR(255) NULL,
    description  TEXT NULL,
    UNIQUE KEY uq_video_translations (video_id, lang),
    CONSTRAINT fk_video_translations_video FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
