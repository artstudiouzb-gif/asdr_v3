-- 2FA портала /repo через Telegram-бота: привязанный chat_id пользователя
-- портала. Привязан (не NULL) — при входе отправляется одноразовый код в
-- Telegram; отвязка выключает этот второй фактор.
ALTER TABLE repo_users
    ADD COLUMN telegram_chat_id BIGINT NULL AFTER totp_enabled;
