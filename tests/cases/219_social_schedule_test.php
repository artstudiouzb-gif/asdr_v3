<?php

declare(strict_types=1);

use App\Core\SocialSettings;
use App\Models\SocialPost;

/**
 * Отложенная публикация. Очередь и воркер уже были — не хватало времени, с
 * которого задачу можно брать. Редактор готовит новость днём, пост уходит в
 * канал в удобный час.
 */

test('Время отложенной отправки нормализуется, прошедшее — как «сейчас»', function () {
    $now = strtotime('2026-08-02 12:00:00');

    // datetime-local отдаёт «T» вместо пробела и не даёт секунд.
    assert_same('2026-08-05 09:30:00', SocialSettings::normalizeScheduleTime('2026-08-05T09:30', $now));
    assert_same('2026-08-02 12:30:00', SocialSettings::normalizeScheduleTime('2026-08-02 12:30:00', $now));

    // Пусто — отправить при ближайшем запуске воркера.
    assert_same(null, SocialSettings::normalizeScheduleTime('', $now));
    assert_same(null, SocialSettings::normalizeScheduleTime('   ', $now));

    // Прошедшее время не должно превращаться в вечно ждущую задачу.
    assert_same(null, SocialSettings::normalizeScheduleTime('2026-08-01T10:00', $now));
    // Дальше года — почти наверняка опечатка в дате.
    assert_same(null, SocialSettings::normalizeScheduleTime('2126-08-05T09:30', $now));
    assert_same(null, SocialSettings::normalizeScheduleTime('не дата', $now));
});

test('Воркер не берёт задачу раньше назначенного времени (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $newsId = \App\Models\News::create([
        'title' => 'Отложенная', 'slug' => 'sched-' . uniqid(), 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        // Задача на завтра.
        SocialPost::enqueue($newsId, 'telegram', true, date('Y-m-d H:i:s', time() + 86400));
        $mine = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $r): bool => (int) $r['news_id'] === $newsId
        ));
        assert_same([], $mine(SocialPost::pendingBatch(50)), 'время не пришло — задача не берётся');

        // Время наступило — задача становится доступной.
        $pdo->prepare('UPDATE social_posts SET scheduled_at = (NOW() - INTERVAL 1 MINUTE), locked_until = NULL WHERE news_id = :id')
            ->execute([':id' => $newsId]);
        assert_same(1, count($mine(SocialPost::pendingBatch(50))), 'наступившее время освобождает задачу');
    } finally {
        $pdo->prepare('DELETE FROM social_posts WHERE news_id = :id')->execute([':id' => $newsId]);
        $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $newsId]);
    }
});

test('Запланированный пост не считается застрявшей очередью (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $newsId = \App\Models\News::create([
        'title' => 'Отложенная-2', 'slug' => 'sched2-' . uniqid(), 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        // Создана давно, но запланирована на завтра: сторож тишины не должен
        // объявлять тревогу — задача ждёт законно.
        $pdo->prepare(
            "INSERT INTO social_posts (news_id, network, status, scheduled_at, created_at)
             VALUES (:nid, 'telegram', 'pending', (NOW() + INTERVAL 1 DAY), NOW() - INTERVAL 5 HOUR)"
        )->execute([':nid' => $newsId]);
        assert_false(isset(\App\Core\Watchdog::check()['queue:social_posts']));

        // А вот пропущенное время — уже настоящая остановка.
        $pdo->prepare('UPDATE social_posts SET scheduled_at = (NOW() - INTERVAL 5 HOUR) WHERE news_id = :id')
            ->execute([':id' => $newsId]);
        assert_true(isset(\App\Core\Watchdog::check()['queue:social_posts']));
    } finally {
        $pdo->prepare('DELETE FROM social_posts WHERE news_id = :id')->execute([':id' => $newsId]);
        $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $newsId]);
    }
});

test('Список новостей показывает время отложенной публикации (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $newsId = \App\Models\News::create([
        'title' => 'Отложенная-3', 'slug' => 'sched3-' . uniqid(), 'excerpt' => 'А',
        'content' => 'т', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        $when = date('Y-m-d H:i:s', time() + 7200);
        SocialPost::enqueue($newsId, 'telegram', true, $when);

        $status = SocialPost::statusForNewsIds([$newsId])[$newsId] ?? null;
        assert_true($status !== null);
        assert_same($when, (string) $status['scheduled'], 'редактор видит, что пост не завис, а ждёт часа');

        $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/index.php');
        assert_contains("\$ss['scheduled']", $view);
    } finally {
        $pdo->prepare('DELETE FROM social_posts WHERE news_id = :id')->execute([':id' => $newsId]);
        $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $newsId]);
    }
});

test('Схема и миграция описывают колонку одинаково', function () {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    assert_contains('scheduled_at DATETIME NULL', $schema);
    assert_contains('idx_social_posts_scheduled', $schema);
    // Файл миграции обязан быть в списке применённых, иначе чистая установка
    // и обновление разойдутся.
    assert_contains("('2026_08_02_social_scheduled_at.sql')", $schema);
    assert_true(is_file(APP_ROOT . '/database/migrations/2026_08_02_social_scheduled_at.sql'));
});
