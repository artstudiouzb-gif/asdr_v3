ALTER TABLE news ADD COLUMN hashtags VARCHAR(500) NULL AFTER audio_title;
ALTER TABLE news_translations ADD COLUMN hashtags VARCHAR(500) NULL AFTER content;

