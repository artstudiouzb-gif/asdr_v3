-- Переводы фотоальбомов: заголовок и описание на неосновных языках.
CREATE TABLE IF NOT EXISTS photo_album_translations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id     INT UNSIGNED NOT NULL,
    lang         VARCHAR(8) NOT NULL,
    title        VARCHAR(255) NULL,
    description  TEXT NULL,
    UNIQUE KEY uq_photo_album_translations (album_id, lang),
    CONSTRAINT fk_photo_album_translations_album FOREIGN KEY (album_id) REFERENCES photo_albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
