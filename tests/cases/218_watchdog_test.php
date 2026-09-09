<?php

declare(strict_types=1);

use App\Core\Watchdog;

/**
 * Сторож тишины. Об ошибках CMS сообщает сразу, а самый частый отказ на
 * shared-хостинге беззвучный: cron перестал вызывать воркер, очередь стоит,
 * кончилось место. Heartbeat такие признаки считает, но только когда его
 * кто-то спрашивает — сторож спрашивает сам и пишет в Telegram.
 */

function watchdog_heartbeat_file(string $name): string
{
    return APP_ROOT . '/storage/cache/worker_heartbeat_' . $name . '.txt';
}

test('Молчащий воркер попадает в список остановок', function () {
    $file = watchdog_heartbeat_file('social');
    $backup = is_file($file) ? (string) file_get_contents($file) : null;

    try {
        // Свежая метка — проблемы нет.
        file_put_contents($file, (string) time());
        assert_false(isset(Watchdog::check()['worker:social']));

        // Метка двухчасовой давности при ожидании в 30 минут — воркер молчит.
        file_put_contents($file, (string) (time() - 7200));
        $problems = Watchdog::check();
        assert_true(isset($problems['worker:social']), 'молчание воркера замечено');
        assert_contains('cron', $problems['worker:social'], 'подсказка называет вероятную причину');
    } finally {
        if ($backup !== null) {
            file_put_contents($file, $backup);
        } else {
            @unlink($file);
        }
    }
});

test('Воркер, который ни разу не запускался, тревоги не поднимает', function () {
    $file = watchdog_heartbeat_file('backup');
    $backup = is_file($file) ? (string) file_get_contents($file) : null;

    try {
        @unlink($file);
        // Часть воркеров осознанно не настроена — это не отказ.
        assert_false(isset(Watchdog::check()['worker:backup']));
    } finally {
        if ($backup !== null) {
            file_put_contents($file, $backup);
        }
    }
});

test('О проблеме сообщаем один раз, об уходе — отдельно (БД)', function () {
    ensure_test_db();
    $file = watchdog_heartbeat_file('social');
    // Метки соседних воркеров тоже успевают устареть — без изоляции тест
    // ловил чужую тревогу и падал через раз.
    $others = [];
    foreach (glob(APP_ROOT . '/storage/cache/worker_heartbeat_*.txt') ?: [] as $path) {
        if ($path !== $file) {
            $others[$path] = (string) file_get_contents($path);
            @unlink($path);
        }
    }
    $backup = is_file($file) ? (string) file_get_contents($file) : null;
    \App\Models\Setting::set('watchdog_active_alerts', '');

    try {
        file_put_contents($file, (string) (time() - 7200));

        $first = Watchdog::run();
        assert_same(['worker:social'], $first['new'], 'первая тревога объявлена');

        // Проблема та же — второго сообщения быть не должно, иначе каждые
        // 15 минут в чат падает одно и то же.
        $second = Watchdog::run();
        assert_same([], $second['new']);
        assert_same([], $second['resolved']);

        // Воркер ожил — сообщаем об этом отдельно, иначе непонятно, чинить ещё или уже нет.
        file_put_contents($file, (string) time());
        $third = Watchdog::run();
        assert_same(['worker:social'], $third['resolved']);
        assert_same([], $third['problems']);

        // И больше не повторяем.
        assert_same([], Watchdog::run()['resolved']);
    } finally {
        if ($backup !== null) {
            file_put_contents($file, $backup);
        } else {
            @unlink($file);
        }
        foreach ($others as $path => $content) {
            file_put_contents($path, $content);
        }
        \App\Models\Setting::set('watchdog_active_alerts', '');
    }
});

test('Застрявшая очередь публикаций считается остановкой (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $newsId = \App\Models\News::create([
        'title' => 'Очередь', 'slug' => 'watchdog-' . uniqid(), 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        $pdo->prepare(
            "INSERT INTO social_posts (news_id, network, status, created_at)
             VALUES (:nid, 'telegram', 'pending', NOW() - INTERVAL 3 HOUR)"
        )->execute([':nid' => $newsId]);

        $problems = Watchdog::check();
        assert_true(isset($problems['queue:social_posts']), 'задача, ждущая три часа, — это остановка');
        assert_contains('очередь', mb_strtolower($problems['queue:social_posts']));
    } finally {
        $pdo->prepare('DELETE FROM social_posts WHERE news_id = :id')->execute([':id' => $newsId]);
        $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $newsId]);
    }
});

test('Свежая задача в очереди тревоги не поднимает (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $newsId = \App\Models\News::create([
        'title' => 'Свежая', 'slug' => 'watchdog-fresh-' . uniqid(), 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        $pdo->prepare(
            "INSERT INTO social_posts (news_id, network, status, created_at)
             VALUES (:nid, 'telegram', 'pending', NOW())"
        )->execute([':nid' => $newsId]);

        assert_false(isset(Watchdog::check()['queue:social_posts']), 'очередь разбирается воркером — ждать нормально');
    } finally {
        $pdo->prepare('DELETE FROM social_posts WHERE news_id = :id')->execute([':id' => $newsId]);
        $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $newsId]);
    }
});

test('Задание для Cron описано там, где настраиваются уведомления', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/telegram/index.php');
    assert_contains('app/Console/watchdog.php', $view);
    assert_contains('*/15 * * * *', $view);

    assert_true(is_file(APP_ROOT . '/app/Console/watchdog.php'), 'воркер на месте');
});
