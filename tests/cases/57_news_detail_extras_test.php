<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\News;

// Детальная страница новости: экстра-поля, счётчик просмотров, соседние/похожие.

test('News::updateExtras: сохраняет бейдж, тезисы, документы; отсекает javascript:', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO news (title, slug, status, published_at) VALUES ('Экстра', 'test-extras-x1', 'published', NOW())");
    $id = (int) $pdo->lastInsertId();

    News::updateExtras($id, [
        'badge' => 'Мероприятие',
        'press_release_url' => '/uploads/public/p.pdf',
        'key_points' => "Тезис один\nТезис два",
        'event_meta' => "20 мая 2026\nТашкент",
        'docs' => [['title' => 'Пресс-релиз', 'meta' => 'PDF · 1 МБ', 'url' => '/uploads/public/p.pdf']],
        'source_note' => 'Пресс-служба',
    ]);
    $row = News::findById($id);
    assert_same('Мероприятие', (string) $row['badge']);
    assert_contains('Тезис два', (string) $row['key_points']);
    $docs = json_decode((string) $row['docs'], true);
    assert_same('Пресс-релиз', $docs[0]['title']);

    News::incrementViews($id);
    News::incrementViews($id);
    assert_same(2, (int) News::findById($id)['views']);

    $pdo->exec("DELETE FROM news WHERE id = {$id}");
});

test('News::adjacent и related: соседние по дате и похожие без текущей', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $ids = [];
    foreach ([['adj-a', '2026-05-01'], ['adj-b', '2026-05-02'], ['adj-c', '2026-05-03']] as [$slug, $d]) {
        $pdo->exec("INSERT INTO news (title, slug, status, published_at) VALUES ('{$slug}', 'test-{$slug}', 'published', '{$d} 10:00:00')");
        $ids[$slug] = (int) $pdo->lastInsertId();
    }
    $mid = News::findById($ids['adj-b']);
    $adj = News::adjacent($mid);
    assert_same('test-adj-a', (string) $adj['prev']['slug']);
    assert_same('test-adj-c', (string) $adj['next']['slug']);

    $related = News::related($ids['adj-b'], 4);
    $slugs = array_column($related, 'slug');
    assert_true(!in_array('test-adj-b', $slugs, true), 'текущая новость исключена из похожих');

    $pdo->exec('DELETE FROM news WHERE id IN (' . implode(',', $ids) . ')');
});

test('YouTube-видео новости: рекомендации перекрываются своей обложкой', function () {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/news.js');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');

    assert_contains('https://www.youtube.com/iframe_api', $js);
    assert_contains('YT.PlayerState.ENDED', $js);
    assert_contains('player.seekTo(0, true)', $js);
    assert_contains('data-replay-label', $view);
    assert_contains('newsdetail-media--video', $view);
    assert_contains('$layout !== \'video\'', $view, 'У встроенного видеомакета нет дублирующей кнопки «Смотреть видео»');
    assert_contains('$photoStripSlides', $view, 'Обложка видеоплеера не повторяется в нижней фотоленте');
    assert_contains('.news-video__end[hidden]', $css);
});

test('Пресс-релиз не дублирует документы на сайте', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    assert_not_contains("t('Скачать пресс-релиз')", $view);
    assert_contains('$docs[] = [\'title\' => t(\'Пресс-релиз\')', $view);
});
