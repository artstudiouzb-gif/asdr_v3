-- @post-schema
-- High-Performance Composite & Covering Indexes for Enterprise query acceleration
ALTER TABLE `pages` ADD INDEX `idx_pages_home_lookup` (`is_home`, `entity_type`, `status`, `deleted_at`);
ALTER TABLE `audit_log` ADD INDEX `idx_audit_created_user` (`created_at`, `user_id`);
ALTER TABLE `news` ADD INDEX `idx_news_pub_status_id` (`published_at`, `status`, `deleted_at`, `id`);
