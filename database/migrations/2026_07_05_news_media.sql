-- ---------------------------------------------------------------------------
-- Этап 12.1 — медиа-движок новостей: галереи, видео, тип макета, фокальная точка.
-- ---------------------------------------------------------------------------

ALTER TABLE news
    ADD COLUMN video_url   VARCHAR(255) NULL AFTER image,
    ADD COLUMN layout_type ENUM('standard','gallery','video','side_image') NOT NULL DEFAULT 'standard' AFTER video_url,
    ADD COLUMN focal_x     TINYINT UNSIGNED NULL COMMENT 'фокальная точка обложки X, %' AFTER layout_type,
    ADD COLUMN focal_y     TINYINT UNSIGNED NULL COMMENT 'фокальная точка обложки Y, %' AFTER focal_x;

-- Галерея фотографий новости.
CREATE TABLE IF NOT EXISTS news_images (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id     INT UNSIGNED NOT NULL,
    path        VARCHAR(255) NOT NULL,
    alt_text    VARCHAR(255) NULL,
    focal_x     TINYINT UNSIGNED NULL COMMENT 'фокальная точка X, %',
    focal_y     TINYINT UNSIGNED NULL COMMENT 'фокальная точка Y, %',
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_news_images_news (news_id, sort_order),
    CONSTRAINT fk_news_images_news FOREIGN KEY (news_id) REFERENCES news (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
