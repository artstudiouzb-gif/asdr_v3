<?php

declare(strict_types=1);

use App\Core\Database;

// Регрессия: часовой пояс сессии MySQL синхронизируется с PHP. Иначе NOW() в
// БД и published_at, записываемый из PHP, расходятся, и свежие новости/записи
// с фильтром "published_at <= NOW()" прячутся до конца смещения (напр. 3 часа).

test('MySQL NOW() совпадает со временем PHP (пояс сессии выровнен)', function () {
    if (!Database::isConnected()) {
        return; // без БД-окружения тест пропускается (unit-режим)
    }
    $mysqlNow = (int) strtotime((string) Database::pdo()->query('SELECT NOW()')->fetchColumn());
    $phpNow = time();
    assert_true(abs($mysqlNow - $phpNow) < 120, 'расхождение MySQL/PHP времени < 2 минут (пояса выровнены)');
});

test('Свежеопубликованная новость сразу видна в published()', function () {
    if (!Database::isConnected()) {
        return;
    }
    $slug = 'tz-selftest-' . substr(md5((string) mt_rand()), 0, 8);
    $id = \App\Models\News::create([
        'title' => 'TZ selftest', 'slug' => $slug, 'excerpt' => '', 'content' => '',
        'image' => '', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'), 'author_id' => null,
    ]);
    try {
        $ids = array_map(static fn ($n) => (int) $n['id'], \App\Models\News::published(50));
        assert_true(in_array($id, $ids, true), 'новость с published_at=сейчас попадает в published()');
    } finally {
        \App\Models\News::forceDelete($id);
    }
});
