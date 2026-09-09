-- @post-schema
-- WebAuthn / Passkeys credentials for passwordless biometric authentication
CREATE TABLE IF NOT EXISTS `user_passkeys` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `credential_id` VARCHAR(255) NOT NULL,
    `public_key` TEXT NOT NULL,
    `counter` INT UNSIGNED NOT NULL DEFAULT 0,
    `device_name` VARCHAR(100) NOT NULL DEFAULT 'Passkey Device',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_credential_id` (`credential_id`),
    INDEX `idx_user_passkeys_user` (`user_id`),
    CONSTRAINT `fk_user_passkeys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
