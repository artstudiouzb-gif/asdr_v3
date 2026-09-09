-- Раздел «Видео»: обложка + ссылка на видео, флаг «показать на главном».
CREATE TABLE IF NOT EXISTS videos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    slug         VARCHAR(255) NOT NULL,
    description  TEXT NULL,
    cover_url    VARCHAR(500) NOT NULL DEFAULT '',
    video_url    VARCHAR(500) NOT NULL DEFAULT '',
    duration     VARCHAR(20) NOT NULL DEFAULT '',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    is_featured  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_videos_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
