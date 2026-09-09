-- ---------------------------------------------------------------------------
-- Добавление макета сайдбара для новостей
-- ---------------------------------------------------------------------------
ALTER TABLE news ADD COLUMN sidebar_layout ENUM('no_sidebar', 'left_sidebar', 'right_sidebar') NOT NULL DEFAULT 'right_sidebar' AFTER layout_type;
