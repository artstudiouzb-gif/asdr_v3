-- Макет детальной новости «Карточка»: тот же фото-hero, но текст собран в
-- стеклянную панель. Значение добавляется в ENUM — иначе сохранение падает
-- на «Data truncated for column layout_type».
ALTER TABLE news
    MODIFY layout_type ENUM('standard','gallery','video','side_image','premium','card')
        NOT NULL DEFAULT 'standard';
