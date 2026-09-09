<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\MediaCleaner;

test('MediaCleaner::referenceCount учитывает разные таблицы (задача 90)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $url = '/uploads/public/ref-' . bin2hex(random_bytes(3)) . '.jpg';

    // Изначально не используется.
    assert_same(0, MediaCleaner::referenceCount($url));

    // Ссылка в блоке (JSON) + как обложка новости + в галерее.
    $pid = $pdo->query("INSERT INTO pages (title,slug,status) VALUES ('P','refp-" . bin2hex(random_bytes(3)) . "','draft')") !== false
        ? (int) $pdo->lastInsertId() : 0;
    $pdo->prepare('INSERT INTO blocks (page_id,lang,type,data,sort_order) VALUES (?,?,?,?,0)')
        ->execute([$pid, 'ru', 'media_gallery', json_encode(['items' => [['image' => $url, 'title' => 'Фото', 'kind' => 'photo']]])]);

    $nid = $pdo->query("INSERT INTO news (title,slug,status,image) VALUES ('N','refn-" . bin2hex(random_bytes(3)) . "','draft'," . $pdo->quote($url) . ")") !== false
        ? (int) $pdo->lastInsertId() : 0;
    $pdo->prepare('INSERT INTO news_images (news_id,path,sort_order) VALUES (?,?,0)')->execute([$nid, $url]);

    // Ожидаем как минимум 3 упоминания (блок + news.image + news_images.path).
    assert_true(MediaCleaner::referenceCount($url) >= 3, 'должно быть >= 3 упоминаний');
});
