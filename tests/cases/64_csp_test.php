<?php

declare(strict_types=1);

use App\Core\SecurityHeaders;

// CSP публичной части и админки (roadmap v2, раздел «Безопасность»):
// nonce вместо 'unsafe-inline' для скриптов, условные хосты, HSTS preload.

test('SecurityHeaders::nonce: стабилен в запросе, URL-safe base64', function () {
    $n1 = SecurityHeaders::nonce();
    $n2 = SecurityHeaders::nonce();
    assert_same($n1, $n2, 'один nonce на запрос');
    assert_true(strlen($n1) >= 20, 'достаточная длина');
    assert_true((bool) preg_match('/^[A-Za-z0-9_-]+$/', $n1), 'без спецсимволов');
});

test('adminCsp: без внешних CDN (TinyMCE самохостится), unsafe-inline для скриптов отключён', function () {
    $csp = SecurityHeaders::adminCsp('testnonce');
    assert_contains("script-src 'self' 'nonce-testnonce';", $csp);
    assert_contains("style-src 'self' 'unsafe-inline';", $csp);
    assert_true(!str_contains($csp, 'jsdelivr'), 'внешний CDN убран из CSP');
    assert_true(!str_contains($csp, "script-src 'self' 'unsafe-inline'"), 'unsafe-inline для скриптов убран');
    assert_contains("object-src 'none'", $csp);
    assert_contains("frame-ancestors 'self'", $csp);
});

test('publicCsp: базовая политика разрешает YouTube API и официальный Telegram widget', function () {
    $csp = SecurityHeaders::publicCsp('n0nce', []);
    assert_contains("script-src 'self' 'nonce-n0nce' https://www.youtube.com https://telegram.org; ", $csp);
    assert_contains('https://www.youtube.com', $csp, 'разрешён официальный IFrame API для своего финального экрана');
    assert_contains('https://telegram.org', $csp, 'разрешён официальный виджет публикации Telegram');
    assert_true(!str_contains($csp, 'googletagmanager'), 'GA-хостов нет без настройки');
    assert_true(!str_contains($csp, 'fonts.googleapis.com'), 'шрифтовых хостов нет без настройки');
    assert_contains("worker-src 'self'", $csp);
    assert_contains("form-action 'self'", $csp);
});

test('TinyMCE: расширенная панель содержит цитату и индексы', function () {
    $editor = (string) file_get_contents(APP_ROOT . '/public/assets/js/vendor/editor.js');
    assert_contains('subscript superscript | blockquote', $editor);
    assert_contains('articlemedia', $editor, 'единый диалог вставки медиа подключён к панели');
    assert_contains('data-ae-image-caption', $editor, 'подпись фото задаётся до вставки');
    assert_contains('data-ae-image-credit', $editor, 'автор фото задаётся до вставки');
    assert_contains('data-ae-embed-url', $editor, 'поддерживается предварительная проверка социальной ссылки');
    assert_contains("!/^\\/[^/]/.test(url) && !/^https?:\\/\\//i.test(url)", $editor, 'превью фото принимает только локальные и HTTP(S) URL');
    assert_contains('return encodeURI(decodeURI(url));', $editor, 'адрес фото контекстно кодируется перед DOM sink');
});

test('publicCsp: разрешает только известный источник скрипта счётчика', function () {
    $csp = SecurityHeaders::publicCsp('counter', [
        'counter_scripts' => ['https://mc.yandex.ru', 'https://example.test'],
    ]);
    assert_contains("script-src 'self' 'nonce-counter' https://www.youtube.com https://telegram.org https://mc.yandex.ru", $csp);
    assert_not_contains('example.test', $csp);
});

test('publicCsp: хосты добавляются по включённым настройкам', function () {
    $csp = SecurityHeaders::publicCsp('x', ['google_fonts' => true, 'ga' => true, 'ym' => true]);
    assert_not_contains('fonts.googleapis.com', $csp, 'локальным шрифтам внешний style-src не нужен');
    assert_not_contains('fonts.gstatic.com', $csp, 'локальным шрифтам внешний font-src не нужен');
    assert_contains('https://www.googletagmanager.com', $csp);
    assert_contains('https://mc.yandex.ru', $csp);
    assert_contains("script-src 'self' 'nonce-x' https://www.youtube.com https://telegram.org https://www.googletagmanager.com https://mc.yandex.ru", $csp);
});

test('injectScriptNonce: добавляет nonce только тегам без него', function () {
    $html = '<p>текст</p><script>var a=1;</script>'
        . '<script nonce="уже">var b=2;</script>'
        . '<SCRIPT src="/x.js"></SCRIPT>';
    $out = SecurityHeaders::injectScriptNonce($html, 'abc');
    assert_contains('<script nonce="abc">var a=1;</script>', $out);
    assert_contains('<script nonce="уже">var b=2;</script>', $out);
    // Замена нормализует регистр открывающего тега — важен сам nonce.
    assert_contains('<script nonce="abc" src="/x.js">', $out);
    assert_contains('<script nonce="abc" src="/counter.js">', SecurityHeaders::injectScriptNonce('<SCRIPT src="/counter.js"></SCRIPT>', 'abc'));
    assert_same('<p>без скриптов</p>', SecurityHeaders::injectScriptNonce('<p>без скриптов</p>', 'abc'));
});

test('hstsValue: preload по опции', function () {
    assert_same('max-age=31536000; includeSubDomains', SecurityHeaders::hstsValue(false));
    assert_same('max-age=63072000; includeSubDomains; preload', SecurityHeaders::hstsValue(true));
});
