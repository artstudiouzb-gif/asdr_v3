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

    // Пересборка: фото убрали из новости — ушли и из альбома, второго альбома нет.
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

test('Обычная новость и галерея без фото альбома не дают; повтор сборки не дублирует (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $tag = bin2hex(random_bytes(3));
    $base = Language::defaultCode();
    // Галереи, оставшиеся в базе от других сценариев, не должны путать счёт.
    $pdo->exec("UPDATE news SET layout_type = 'standard' WHERE layout_type = 'gallery'");

    $plain = gallery_news('Обычная ' . $tag, 'plain-' . $tag, $base, 'standard');
    gallery_photo($plain, '/uploads/public/p-' . $tag . '.jpg', 1);
    assert_same(null, NewsAlbum::sync($plain), 'не галерея — не альбом');
    $empty = gallery_news('Пустая ' . $tag, 'empty-' . $tag, $base);
    assert_same(null, NewsAlbum::sync($empty), 'галерея без фото и обложки — не альбом');

    $a = gallery_news('Первая ' . $tag, 'g1-' . $tag, $base);
    gallery_photo($a, '/uploads/public/g1-' . $tag . '.jpg', 1);
    $b = gallery_news('Вторая ' . $tag, 'g2-' . $tag, $base);
    $pdo->prepare('UPDATE news SET image = :i WHERE id = :id')->execute([':i' => '/uploads/public/cover-' . $tag . '.jpg', ':id' => $b]);

    $first = NewsAlbum::backfill(20);
    assert_same(2, $first['created'], 'галерея с фото и галерея с одной обложкой');
    assert_same(1, $first['skipped'], 'пустая галерея пропущена');
    $again = NewsAlbum::backfill(20);
    assert_same(0, $again['created'], 'повтор не создаёт');
    assert_same(2, $again['updated']);
    assert_same(['/uploads/public/cover-' . $tag . '.jpg'], array_column(PhotoAlbum::images((int) $pdo->query('SELECT id FROM photo_albums WHERE news_id = ' . $b)->fetchColumn()), 'image_url'), 'без галереи берётся обложка');
});
