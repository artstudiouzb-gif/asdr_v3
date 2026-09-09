-- Фотоальбомы: галереи изображений с обложкой. Публичные страницы /albums
-- и /albums/{slug}; управление в панели (медиатека для выбора фото).
CREATE TABLE IF NOT EXISTS photo_albums (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    slug         VARCHAR(255) NOT NULL,
    description  TEXT NULL,
    cover_url    VARCHAR(500) NOT NULL DEFAULT '',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_albums_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photo_album_images (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id   INT UNSIGNED NOT NULL,
    image_url  VARCHAR(500) NOT NULL,
    caption    VARCHAR(255) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_album_images (album_id, sort_order, id),
    CONSTRAINT fk_album_images FOREIGN KEY (album_id) REFERENCES photo_albums (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
