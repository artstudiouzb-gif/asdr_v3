-- Связывает подписчика, дайджест и почтовую очередь:
-- язык рассылки, источник/согласие, мягкая отписка и защита от дублей.
-- Каждый DDL-шаг идемпотентен после частично выполненной миграции.

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers' AND COLUMN_NAME = 'status'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE subscribers ADD COLUMN status ENUM(''active'',''unsubscribed'') NOT NULL DEFAULT ''active'' AFTER token',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers' AND COLUMN_NAME = 'lang'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE subscribers ADD COLUMN lang VARCHAR(8) NOT NULL DEFAULT ''ru'' AFTER status',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers' AND COLUMN_NAME = 'source'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE subscribers ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT ''website'' AFTER lang',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers' AND COLUMN_NAME = 'consent_at'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE subscribers ADD COLUMN consent_at DATETIME NULL AFTER source',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers' AND COLUMN_NAME = 'unsubscribed_at'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE subscribers ADD COLUMN unsubscribed_at DATETIME NULL AFTER consent_at',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers' AND COLUMN_NAME = 'last_digest_at'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE subscribers ADD COLUMN last_digest_at DATETIME NULL AFTER unsubscribed_at',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers' AND COLUMN_NAME = 'updated_at'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE subscribers ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscribers'
      AND INDEX_NAME = 'idx_subscribers_status_lang'
);
SET @asdr_sql := IF(
    @asdr_has_index = 0,
    'ALTER TABLE subscribers ADD KEY idx_subscribers_status_lang (status, lang, created_at)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_queue' AND COLUMN_NAME = 'subscriber_id'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE mail_queue ADD COLUMN subscriber_id INT UNSIGNED NULL AFTER to_name',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_queue' AND COLUMN_NAME = 'purpose'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE mail_queue ADD COLUMN purpose VARCHAR(30) NOT NULL DEFAULT ''transactional'' AFTER subscriber_id',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_queue' AND COLUMN_NAME = 'dedupe_key'
);
SET @asdr_sql := IF(
    @asdr_has_column = 0,
    'ALTER TABLE mail_queue ADD COLUMN dedupe_key VARCHAR(190) NULL AFTER purpose',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

ALTER TABLE mail_queue
    MODIFY COLUMN status ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending';

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_queue'
      AND INDEX_NAME = 'idx_mail_queue_subscriber'
);
SET @asdr_sql := IF(
    @asdr_has_index = 0,
    'ALTER TABLE mail_queue ADD KEY idx_mail_queue_subscriber (subscriber_id, purpose, status)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_queue'
      AND INDEX_NAME = 'uq_mail_queue_dedupe'
);
SET @asdr_sql := IF(
    @asdr_has_index = 0,
    'ALTER TABLE mail_queue ADD UNIQUE KEY uq_mail_queue_dedupe (dedupe_key)',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;

SET @asdr_has_fk := (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_queue'
      AND COLUMN_NAME = 'subscriber_id' AND REFERENCED_TABLE_NAME = 'subscribers'
      AND REFERENCED_COLUMN_NAME = 'id'
);
SET @asdr_sql := IF(
    @asdr_has_fk = 0,
    'ALTER TABLE mail_queue ADD CONSTRAINT fk_mail_queue_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE asdr_stmt FROM @asdr_sql; EXECUTE asdr_stmt; DEALLOCATE PREPARE asdr_stmt;
