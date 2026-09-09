-- Защита от повтора кода TOTP (RFC 6238 §5.2): номер последнего принятого
-- шага времени. Без неё подсмотренный шестизначный код оставался рабочим до
-- полутора минут — окно проверки ±1 шаг по 30 секунд.
ALTER TABLE users
    ADD COLUMN totp_last_step BIGINT NULL DEFAULT NULL COMMENT 'последний принятый шаг TOTP (защита от повтора кода)' AFTER totp_enabled;

ALTER TABLE repo_users
    ADD COLUMN totp_last_step BIGINT NULL DEFAULT NULL COMMENT 'последний принятый шаг TOTP (защита от повтора кода)' AFTER totp_enabled;
