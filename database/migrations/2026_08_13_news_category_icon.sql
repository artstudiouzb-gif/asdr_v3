-- Иконка рубрики новостей: ключ Tabler, выбирается тем же пикером, что и в
-- блоках. Пусто = рубрика показывается только названием.
ALTER TABLE news_categories
    ADD COLUMN icon VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'ключ иконки Tabler' AFTER slug;
