<?php

declare(strict_types=1);

use App\Core\Cache;

test('Cache::forgetPrefix удаляет закешированные файлы и каталоги без багов двоеточия', function (): void {
    Cache::put('page:9999:ru', ['html' => '<h1>Hello</h1>']);
    Cache::put('page:9999:uz', ['html' => '<h1>Salom</h1>']);

    assert_true(Cache::get('page:9999:ru') !== null, 'Кэш записался');

    Cache::forgetPrefix('page:9999');
    assert_equals(null, Cache::get('page:9999:ru'), 'Кэш страницы 9999 удален');
    assert_equals(null, Cache::get('page:9999:uz'), 'Кэш страницы 9999 на узбекском удален');

    Cache::put('page:8888:ru', ['html' => '<h1>Test</h1>']);
    Cache::forgetPrefix('page:');
    assert_equals(null, Cache::get('page:8888:ru'), 'forgetPrefix page: очистил все кэши страниц');
});
