-- ---------------------------------------------------------------------------
-- Бесплатная доставка кодов входа через собственного Telegram-бота (Bot API,
-- токен от @BotFather). Альтернатива платному Telegram Gateway: если задан
-- telegram_bot_token и админ привязал свой chat_id — код шлёт бот; иначе,
-- при настроенном шлюзе и телефоне, — канал Verification Codes.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN telegram_chat_id BIGINT NULL COMMENT 'chat_id привязанного Telegram-аккаунта (коды входа через бота)' AFTER phone;

INSERT INTO settings (`key`, `value`) VALUES ('telegram_bot_token', '')
ON DUPLICATE KEY UPDATE `key` = `key`;
