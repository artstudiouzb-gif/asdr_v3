-- Добавление полей аудиозаписи/подкаста для новостей
ALTER TABLE news ADD COLUMN audio_url VARCHAR(500) NULL AFTER video_url;
ALTER TABLE news ADD COLUMN audio_title VARCHAR(255) NULL AFTER audio_url;
