-- Премиум-тип детальной новости: полноэкранный тёмный hero с фото-фоном,
-- галерея в правой колонке, оглавление статьи.
ALTER TABLE news
    MODIFY layout_type ENUM('standard','gallery','video','side_image','premium') NOT NULL DEFAULT 'standard';
