<?php

declare(strict_types=1);

use App\Core\Cache;
use App\Core\Uploader;

test('Memory Guard: parseIniBytes и оценка памяти изображения', function () {
    assert_same(256 * 1024 * 1024, Uploader::parseIniBytes('256M'));
    assert_same(1024 * 1024 * 1024, Uploader::parseIniBytes('1G'));
    assert_same(64 * 1024, Uploader::parseIniBytes('64K'));
    assert_same(12345, Uploader::parseIniBytes('12345'));
    assert_same(PHP_INT_MAX, Uploader::parseIniBytes('-1'));

    assert_same(1920 * 1080 * 5, Uploader::estimateImageMemory(1920, 1080));
});

test('Memory Guard: 8000×6000 отклоняется, обычное фото проходит', function () {
    $limit = 256 * 1024 * 1024; // 256 МБ
    $used = 32 * 1024 * 1024;

    // 48 Мп — выше жёсткого предела, отказ независимо от памяти.
    assert_false(Uploader::imageDecodable(8000, 6000, PHP_INT_MAX, 0));

    // 1920×1080 (~10 МБ) — умещается.
    assert_true(Uploader::imageDecodable(1920, 1080, $limit, $used));

    // 6000×6000 (~172 МБ) при занятых 128 МБ из 256 МБ — не умещается.
    assert_false(Uploader::imageDecodable(6000, 6000, $limit, 128 * 1024 * 1024));

    // Мусорные размеры.
    assert_false(Uploader::imageDecodable(0, 100));
    assert_false(Uploader::imageDecodable(100, -5));
});

test('Cache stampede: remember генерирует один раз, ждущий поток читает чужой кэш', function () {
    $key = 'stampede:test_' . bin2hex(random_bytes(3));
    Cache::forget($key);

    // Обычный путь: вычислили и закэшировали, второй вызов — из кэша.
    $calls = 0;
    $value = Cache::remember($key, function () use (&$calls) {
        $calls++;
        return 'computed';
    });
    assert_same('computed', $value);
    assert_same('computed', Cache::remember($key, function () use (&$calls) {
        $calls++;
        return 'other';
    }));
    assert_same(1, $calls, 'колбэк вызван только один раз');
    Cache::forget($key);

    // Конкуренция: подпроцесс держит flock и записывает кэш через 0.4 с;
    // наш remember должен дождаться его значения, не вызывая свой колбэк.
    if (PHP_OS_FAMILY === 'Unknown') {
        skip_test('PHP-WASM не разделяет файловую систему с дочерним PHP-процессом');
    }
    $key2 = 'stampede:race_' . bin2hex(random_bytes(3));
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', explode(':', $key2)[1]);
    $dir = APP_ROOT . '/storage/cache/stampede';
    @mkdir($dir, 0755, true);
    $cachePath = $dir . '/' . $safe . '.cache';
    $lockPath = $cachePath . '.lock';

    // Подпроцесс сообщает о взятом lock файлом-маркером, а родитель ждёт этот
    // маркер вместо фиксированного usleep: старт PHP в контейнере под нагрузкой
    // легко превышал 150 мс, и тогда lock первым брал сам тест — падение было
    // гонкой в тесте, а не в Cache::remember.
    $readyPath = $cachePath . '.ready';
    $script = sprintf(
        '$l=fopen(%s,"c");flock($l,LOCK_EX);touch(%s);usleep(400000);'
        . 'file_put_contents(%s,serialize("from-other"),LOCK_EX);flock($l,LOCK_UN);',
        var_export($lockPath, true),
        var_export($readyPath, true),
        var_export($cachePath, true)
    );
    $proc = proc_open([PHP_BINARY, '-r', $script], [], $pipes);

    $deadline = microtime(true) + 10.0;
    while (!is_file($readyPath) && microtime(true) < $deadline) {
        usleep(5000);
    }
    assert_true(is_file($readyPath), 'подпроцесс не захватил lock за отведённое время');

    $raced = Cache::remember($key2, function () {
        return 'should-not-run';
    });
    assert_same('from-other', $raced, 'значение получено из чужой генерации');

    proc_close($proc);
    @unlink($readyPath);
    Cache::forget($key2);
});

test('QueueClaim: пачка забирается эксклюзивно, аренда истекает (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    // Колонка locked_until (идемпотентно).
    try {
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_queue_locks.sql'));
    } catch (\Throwable) {
        // Игнорируем если колонка уже существует в schema.sql
    }
    $pdo->exec("DELETE FROM mail_queue WHERE to_email LIKE 'claim-%'");

    \App\Models\MailQueue::enqueue('claim-1@example.com', 'S1', 'B1');
    \App\Models\MailQueue::enqueue('claim-2@example.com', 'S2', 'B2');

    $batch = \App\Core\QueueClaim::batch('mail_queue', 3, 10);
    $mine = array_values(array_filter($batch, static fn ($r) => str_starts_with((string) $r['to_email'], 'claim-')));
    assert_same(2, count($mine), 'обе задачи забраны');

    // Повторный вызов (второй «воркер») наши строки не получает — аренда.
    $batch2 = \App\Core\QueueClaim::batch('mail_queue', 3, 10);
    $stolen = array_filter($batch2, static fn ($r) => str_starts_with((string) $r['to_email'], 'claim-'));
    assert_same(0, count($stolen), 'занятые строки не выдаются повторно');

    // Аренда истекла (воркер упал) — строки снова доступны.
    $pdo->exec("UPDATE mail_queue SET locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE to_email LIKE 'claim-%'");
    $batch3 = \App\Core\QueueClaim::batch('mail_queue', 3, 10);
    $again = array_filter($batch3, static fn ($r) => str_starts_with((string) $r['to_email'], 'claim-'));
    assert_same(2, count($again), 'после истечения аренды строки выдаются вновь');

    // Неизвестная таблица — исключение (защита от инъекций).
    $threw = false;
    try {
        \App\Core\QueueClaim::batch('users; DROP TABLE users', 3, 10);
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    assert_true($threw);

    $pdo->exec("DELETE FROM mail_queue WHERE to_email LIKE 'claim-%'");
});
