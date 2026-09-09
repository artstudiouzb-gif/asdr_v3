<?php

declare(strict_types=1);

use App\Models\News;
use App\Models\SearchLog;

test('SearchLog и news_views аналитика дашборда', static function () {
    if (!isset($_ENV['TEST_DB_DATABASE']) && getenv('TEST_DB_DATABASE') === false) {
        skip_test('TEST_DB_* не заданы');
    }

    // 1. Проверка логирования поиска
    SearchLog::record('тест поиска', 5);
    $popular = SearchLog::popular(30, 5);
    assert_true(is_array($popular), 'SearchLog::popular возвращает массив');

    // 2. Проверка инкремента просмотров новости
    $db = \App\Core\Database::pdo();
    $db->exec("INSERT INTO news (title, slug, status) VALUES ('Тестовая новость просмотров', 'test-view-news', 'published') ON DUPLICATE KEY UPDATE title=VALUES(title)");
    $newsId = (int) $db->lastInsertId();
    if ($newsId > 0) {
        News::incrementViews($newsId);
        $topRead = News::mostViewed(30, 5);
        assert_true(is_array($topRead), 'News::mostViewed возвращает массив');
    }
});
