<?php

declare(strict_types=1);

use App\Core\Cache;

test('Cache TTL: свежая запись читается, устаревшая пересобирается', function () {
    $key = 'test:ttl:' . bin2hex(random_bytes(4));
    $calls = 0;
    $gen = function () use (&$calls) { $calls++; return 'v' . $calls; };

    // Первая генерация.
    assert_same('v1', Cache::remember($key, $gen, 3600));
    // В пределах TTL — берётся кэш, генератор не вызывается.
    assert_same('v1', Cache::remember($key, $gen, 3600));
    assert_same(1, $calls, 'при живом TTL генерация одна');

    // Состарим файл принудительно (mtime в прошлом) → должно пересобраться.
    $ref = new ReflectionMethod(Cache::class, 'pathFor');
    $path = $ref->invoke(null, $key);
    touch($path, time() - 7200);
    clearstatcache(true, $path);

    assert_same('v2', Cache::remember($key, $gen, 3600), 'устаревший кэш пересобран');
    assert_same(2, $calls);

    // TTL=0 — вечный кэш (до forget).
    Cache::forget($key);
    assert_same('v3', Cache::remember($key, $gen, 0));
    touch($path, time() - 999999);
    clearstatcache(true, $path);
    assert_same('v3', Cache::remember($key, $gen, 0), 'без TTL возраст не важен');

    Cache::forget($key);
});
