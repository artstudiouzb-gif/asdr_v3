<?php

declare(strict_types=1);

use App\Core\UrlGuard;

test('UrlGuard: разрешает относительные и http(s)-ссылки', function () {
    assert_true(UrlGuard::isSafeLink('/about'));
    assert_true(UrlGuard::isSafeLink('https://example.com'));
    assert_true(UrlGuard::isSafeLink('mailto:a@b.com'));
});

test('UrlGuard: блокирует javascript:/vbscript: в ссылках', function () {
    assert_false(UrlGuard::isSafeLink('javascript:alert(1)'));
    assert_false(UrlGuard::isSafeLink('vbscript:msgbox'));
    assert_false(UrlGuard::isSafeLink("https://example.com/\njavascript:alert(1)"));
});

test('UrlGuard: медиа разрешает только корневые локальные и явные http(s) URL', function () {
    assert_true(UrlGuard::isSafeMedia('/uploads/public/image.jpg'));
    assert_true(UrlGuard::isSafeMedia('https://cdn.example.com/image.jpg'));
    assert_false(UrlGuard::isSafeMedia('//cdn.example.com/image.jpg'));
    assert_false(UrlGuard::isSafeMedia('../../etc/passwd'));
    assert_false(UrlGuard::isSafeMedia('images/photo.jpg'));
    assert_false(UrlGuard::isSafeMedia('javascript:alert(1)'));
    assert_false(UrlGuard::isSafeMedia('data:image/svg+xml,<svg></svg>'));
    assert_false(UrlGuard::isSafeMedia('mailto:editor@example.com'));
    assert_false(UrlGuard::isSafeMedia('tel:+998900000000'));
    assert_false(UrlGuard::isSafeMedia('#image'));
});

test('UrlGuard: isSafeRemote отклоняет loopback/приватные адреса (SSRF)', function () {
    assert_false(UrlGuard::isSafeRemote('http://127.0.0.1/'));
    assert_false(UrlGuard::isSafeRemote('http://localhost/'));
    assert_false(UrlGuard::isSafeRemote('http://169.254.169.254/latest/meta-data'));
    assert_false(UrlGuard::isSafeRemote('http://192.168.1.1/'));
    assert_false(UrlGuard::isSafeRemote('ftp://example.com/'));
});

test('UrlGuard: isSafeRemote допускает публичный IP', function () {
    assert_true(UrlGuard::isSafeRemote('https://93.184.216.34/'));
});
