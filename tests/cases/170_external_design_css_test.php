<?php

declare(strict_types=1);

use App\Core\GeneratedCss;

test('dynamic design CSS is published as an immutable external asset', function (): void {
    $css = ':root{--test-generated-css:#123456;}';
    $first = GeneratedCss::publish($css, 'test-theme');
    $second = GeneratedCss::publish($css, 'test-theme');

    assert_true(is_string($first) && $first !== '');
    assert_same($first, $second, 'одинаковое содержимое получает стабильный URL');
    assert_contains('/uploads/public/generated-css/test-theme-', $first);
    assert_contains('.css?v=', $first);

    $publicPath = (string) parse_url($first, PHP_URL_PATH);
    $diskPath = APP_ROOT . '/public' . $publicPath;
    assert_true(is_file($diskPath), 'CSS опубликован как статический файл');
    assert_contains($css, (string) file_get_contents($diskPath));
});

test('public header has no inline CSS or embedded style blocks', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $theme = theme_css();

    assert_not_contains(' style="', $header);
    assert_not_contains('<style', $header);
    assert_contains('GeneratedCss::publish', $header);
    assert_contains('data-generated-site-css', $header);
    assert_contains('site-submenu--cols-', $header);
    assert_contains('.site-submenu--cols-2', $theme);
    assert_contains('.site-submenu--cols-4', $theme);
});
