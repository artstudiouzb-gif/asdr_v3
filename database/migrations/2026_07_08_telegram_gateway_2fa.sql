-- ---------------------------------------------------------------------------
-- Подтверждение входа в админку кодом через Telegram Gateway API (официальный
-- канал t.me/VerificationCodes). TOTP и backup-коды для админки отключены —
-- вместо них одноразовый код, отправляемый на телефон администратора в
-- Telegram. Токен шлюза хранится в settings (telegram_gateway_token).
-- ---------------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN phone VARCHAR(20) NULL COMMENT 'телефон в формате E.164 (+998...) для кода входа через Telegram' AFTER email;

INSERT INTO settings (`key`, `value`) VALUES ('telegram_gateway_token', '')
ON DUPLICATE KEY UPDATE `key` = `key`;
