-- Telegram-канал в авто-публикации: расширение ENUM сети очереди.
ALTER TABLE social_posts
    MODIFY network ENUM('telegram','facebook','linkedin','instagram') NOT NULL;
