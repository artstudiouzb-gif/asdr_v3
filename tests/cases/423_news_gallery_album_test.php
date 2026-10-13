<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\NewsAlbum;
use App\Models\Language;
use App\Models\PhotoAlbum;

/*
 * Альбом из новости-галереи. Альбом — производная запись: сохранение новости
 * пересобирает его, повтор не создаёт второго, а текст события остаётся в
 * новости — альбом ведёт к ней ссылкой.
 */

function gallery_news(string $title, string $slug, string $lang, string $layout = 'gallery', string $status = 'published', ?int $group = null): int
{
    $pdo = Database::pdo();
    $pdo->prepare(
        "INSERT INTO news (title, slug, excerpt, content, status, layout_type, lang, translation_group_id, published_at, created_at)
         VALUES (:t, :s, '', '', :st, :l, :lang, :g, '2026-05-01 10:00:00', NOW())"
    )->execute([':t' => $title, ':s' => $slug, ':st' => $status, ':l' => $layout, ':lang' => $lang, ':g' => $group]);
    $id = (int) $pdo->lastInsertId();
    if ($group === null) {
        $pdo->prepare('UPDATE news SET translation_group_id = id WHERE id = :id')->execute([':id' => $id]);
    }

    return $id;
}

function gallery_photo(int $newsId, string $path, int $order, string $caption = ''): void
{
    Database::pdo()->prepare('INSERT INTO news_images (news_id, path, caption, sort_order) VALUES (:n, :p, :c, :o)')
        ->execute([':n' => $newsId, ':p' => $path, ':c' => $caption, ':o' => $order]);
}

test('Новость-галерея собирает альбом: заголовок, фото, перевод и ссылка обратно (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $tag = bin2hex(random_bytes(3));
    $base = Language::defaultCode();
    $other = $base === 'ru' ? 'uz' : 'ru';

    $id = gallery_news('Форум ' . $tag, 'forum-' . $tag, $base);
    $translation = gallery_news('Forum tr ' . $tag, 'forum-tr-' . $tag, $other, 'gallery', 'published', $id);
    gallery_photo($id, '/uploads/public/a-' . $tag . '.jpg', 1, 'Открытие');
    gallery_photo($id, '/uploads/public/b-' . $tag . '.jpg', 2);

    // Сохраняют обычно языковую версию — альбом всё равно один, от основной.
    $albumId = NewsAlbum::sync($translation);
    assert_true($albumId !== null && $albumId > 0, 'альбом собран');
    $album = PhotoAlbum::findById((int) $albumId);
    assert_same('Форум ' . $tag, $album['title'], 'заголовок — у записи основного языка');
    assert_same($id, (int) $album['news_id'], 'альбом помнит новость');
    assert_same(1, (int) $album['is_published']);
    assert_same('', (string) ($album['description'] ?? ''), 'текст события остаётся в новости');
    assert_same('2026-05-01 10:00:00', (string) $album['created_at'], 'дата альбома — дата новости');
    $images = PhotoAlbum::images((int) $albumId);
    assert_same(['/uploads/public/a-' . $tag . '.jpg', '/uploads/public/b-' . $tag . '.jpg'], array_column($images, 'image_url'));
    assert_same('Открытие', $images[0]['caption'], 'подпись фото переезжает');
    $tr = $pdo->query('SELECT lang, title FROM photo_album_translations WHERE album_id = ' . (int) $albumId)->fetchAll(PDO::FETCH_ASSOC);
    assert_same([['lang' => $other, 'title' => 'Forum tr ' . $tag]], $tr, 'заголовок перевода — перевод альбома');

    $url = (string) NewsAlbum::newsUrl($album, $other);
    assert_true(str_ends_with($url, '/news/forum-tr-' . $tag), 'ссылка ведёт на новость языка посетителя: ' . $url);

    // Пересборка: фото убрали из новости — ушли и из альбома, второго альбома
    // нет. Созданный альбом следует за новостью, даже когда кадр остался один.
    $pdo->prepare('DELETE FROM news_images WHERE news_id = :n AND sort_order = 1')->execute([':n' => $id]);
    assert_same($albumId, NewsAlbum::sync($id), 'тот же альбом');
    assert_same(1, count(PhotoAlbum::images((int) $albumId)));
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM photo_albums WHERE news_id = ' . $id)->fetchColumn());

    // Снятая с публикации новость прячет альбом.
    $pdo->prepare("UPDATE news SET status = 'draft' WHERE id IN (:a, :b)")->execute([':a' => $id, ':b' => $translation]);
    NewsAlbum::sync($id);
    assert_same(0, (int) PhotoAlbum::findById((int) $albumId)['is_published']);
    assert_same(null, NewsAlbum::newsUrl(PhotoAlbum::findById((int) $albumId), $base), 'на снятую новость ссылки нет');
});

test('Альбом даёт то, что сайт показывает галереей, включая «Умный макет»; повтор не дублирует (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $tag = bin2hex(random_bytes(3));
    $base = Language::defaultCode();
    // Новости, оставшиеся в базе от других сценариев, не должны путать счёт
    // (сценарий идёт в своей транзакции, раннер её откатит).
    $pdo->exec("UPDATE news SET status = 'draft' WHERE status = 'published'");

    // Один кадр — сайт показывает обложку, не галерею.
    $single = gallery_news('Один кадр ' . $tag, 'single-' . $tag, $base, 'standard');
    gallery_photo($single, '/uploads/public/p-' . $tag . '.jpg', 1);
    assert_same(null, NewsAlbum::sync($single), 'один кадр — не альбом');
    $empty = gallery_news('Пустая ' . $tag, 'empty-' . $tag, $base);
    assert_same(null, NewsAlbum::sync($empty), 'галерея без фото — не альбом');
    // Вид «Видео» галереей не бывает, сколько бы фото ни было.
    $video = gallery_news('Видео ' . $tag, 'video-' . $tag, $base, 'video');
    gallery_photo($video, '/uploads/public/v1-' . $tag . '.jpg', 1);
    gallery_photo($video, '/uploads/public/v2-' . $tag . '.jpg', 2);
    assert_same(null, NewsAlbum::sync($video));

    // «Умный макет»: обложка + фото галереи (обложка в галерее продублирована).
    $smart = gallery_news('Умный ' . $tag, 'smart-' . $tag, $base, 'standard');
    $pdo->prepare('UPDATE news SET image = :i WHERE id = :id')->execute([':i' => '/uploads/public/cover-' . $tag . '.jpg', ':id' => $smart]);
    gallery_photo($smart, '/uploads/public/cover-' . $tag . '.jpg', 1, 'Обложка');
    gallery_photo($smart, '/uploads/public/s2-' . $tag . '.jpg', 2);
    $explicit = gallery_news('Явная ' . $tag, 'explicit-' . $tag, $base);
    gallery_photo($explicit, '/uploads/public/e1-' . $tag . '.jpg', 1);
    gallery_photo($explicit, '/uploads/public/e2-' . $tag . '.jpg', 2);

    $first = NewsAlbum::backfill(20);
    assert_same(2, $first['created'], '«Умный макет» с двумя кадрами и явная «Галерея»');
    $smartImages = PhotoAlbum::images((int) $pdo->query('SELECT id FROM photo_albums WHERE news_id = ' . $smart)->fetchColumn());
    assert_same(['/uploads/public/cover-' . $tag . '.jpg', '/uploads/public/s2-' . $tag . '.jpg'], array_column($smartImages, 'image_url'), 'кадры — как на странице новости: обложка без повтора');
    assert_same('Обложка', $smartImages[0]['caption']);

    $again = NewsAlbum::backfill(20);
    assert_same(0, $again['created'], 'повтор не создаёт');
    assert_same(2, $again['updated']);
    assert_same(1, NewsAlbum::backfill(1)['updated'], 'лимит считает альбомы, а не просмотренные новости');
});
