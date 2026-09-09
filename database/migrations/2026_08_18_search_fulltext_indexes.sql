-- @post-schema
-- Enterprise MySQL FULLTEXT Indexes for high-speed site search
ALTER TABLE `news` ADD FULLTEXT INDEX `ft_news_content` (`title`, `excerpt`, `content`);
ALTER TABLE `pages` ADD FULLTEXT INDEX `ft_pages_content` (`title`, `lead`);
