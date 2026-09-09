<?php

declare(strict_types=1);

use App\Core\Asset;

test('Asset::url добавляет ?v= для существующего файла', function () {
    // frontend.css существует в public/assets/css.
    $url = Asset::url('/assets/css/frontend.css');
    assert_contains('/assets/css/frontend.css?v=', $url);
    assert_true((bool) preg_match('/\?v=[0-9a-f]{1,8}$/', $url), 'должен быть hex-хэш версии');
});

test('Asset::url не трогает несуществующий или внешний путь', function () {
    assert_same('/nope/missing.css', Asset::url('/nope/missing.css'));
    assert_same('https://cdn.example.com/x.js', Asset::url('https://cdn.example.com/x.js'));
    assert_same('', Asset::url(''));
});
