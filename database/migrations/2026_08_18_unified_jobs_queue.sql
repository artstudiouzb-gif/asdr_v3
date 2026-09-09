-- @post-schema
-- Unified background jobs queue for ASDR CMS Enterprise
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue` VARCHAR(64) NOT NULL DEFAULT 'default',
    `handler` VARCHAR(191) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `priority` TINYINT NOT NULL DEFAULT 10,
    `status` ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `locked_until` DATETIME NULL DEFAULT NULL,
    `last_error` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_jobs_queue_status_avail` (`queue`, `status`, `available_at`, `priority`),
    INDEX `idx_jobs_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
