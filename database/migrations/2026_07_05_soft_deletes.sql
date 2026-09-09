-- Миграция: мягкое удаление (корзина) для страниц, новостей и проектов.

ALTER TABLE pages    ADD COLUMN deleted_at DATETIME NULL COMMENT 'мягкое удаление (корзина)' AFTER updated_at;
ALTER TABLE news     ADD COLUMN deleted_at DATETIME NULL COMMENT 'мягкое удаление (корзина)' AFTER updated_at;
ALTER TABLE projects ADD COLUMN deleted_at DATETIME NULL COMMENT 'мягкое удаление (корзина)' AFTER updated_at;
