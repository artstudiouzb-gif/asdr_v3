-- Индекс поиска по сайту (App\Core\SearchIndex): текст страниц с блоками,
-- новостей, проектов, документов, сотрудников, записей каталогов и
-- альбомов, очищенный от разметки. Производная таблица — пересобирается
-- целиком после правок контента и ночью.
CREATE TABLE IF NOT EXISTS search_index (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity       VARCHAR(20)  NOT NULL COMMENT 'page|project|news|document|team|entry|album',
    entity_id    INT UNSIGNED NOT NULL,
    lang         VARCHAR(8)   NOT NULL,
    type_label   VARCHAR(120) NOT NULL,
    title        VARCHAR(500) NOT NULL,
    slug         VARCHAR(255) NOT NULL DEFAULT '',
    url          VARCHAR(1000) NOT NULL,
    excerpt      VARCHAR(500) NOT NULL DEFAULT '',
    body         MEDIUMTEXT   NOT NULL,
    sort_date    DATETIME     NULL,
    visible_from DATETIME     NULL COMMENT 'дата публикации новости: до неё результат не показывается',
    KEY idx_search_index_lang (lang, sort_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
