<?php

declare(strict_types=1);

use App\Core\Uploader;

/*
 * Находки аудита безопасности (октябрь 2026). Каждый сценарий держит
 * границу, которая была открыта: вернётся дефект — упадёт тест.
 */

test('SVG: корень не из пространства SVG заменяется заглушкой', function (): void {
    $out = Uploader::sanitizeSvgString('<html xmlns="http://www.w3.org/1999/xhtml"><body><p>x</p></body></html>');

    assert_same('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>', $out);
});

test('SVG: элементы чужого пространства имён вырезаются', function (): void {
    $out = Uploader::sanitizeSvgString(
        '<svg xmlns="http://www.w3.org/2000/svg"><g><p xmlns="http://www.w3.org/1999/xhtml">x</p></g><rect width="1" height="1"/></svg>'
    );

    assert_not_contains('<p', $out);
    assert_contains('<rect', $out, 'разметка самого SVG остаётся');
});

test('SVG: схема ссылки сверяется без пробелов и при любом префиксе', function (): void {
    // Перевод строки внутри схемы браузер выбрасывает, а префикс
    // пространства имён у ссылки выбирает автор файла.
    $out = Uploader::sanitizeSvgString(
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:q="http://www.w3.org/1999/xlink">'
        . '<a q:href="java&#10;script:void(0)"><text>a</text></a>'
        . '<a href="vb&#9;script:x"><text>b</text></a></svg>'
    );

    assert_not_contains('href=', $out);
});

test('SVG: обычные ссылки и якоря сохраняются', function (): void {
    $out = Uploader::sanitizeSvgString(
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
        . '<defs><path id="p" d="M0 0"/></defs><use xlink:href="#p"/>'
        . '<a href="https://example.org/"><text>c</text></a></svg>'
    );

    assert_contains('xlink:href="#p"', $out);
    assert_contains('href="https://example.org/"', $out);
});

test('SVG из загрузок отдаётся с запрещающей CSP', function (): void {
    foreach (['/public/.htaccess', '/.htaccess'] as $file) {
        $htaccess = (string) file_get_contents(APP_ROOT . $file);
        assert_true((bool) preg_match('~<FilesMatch "\\\\\.svg\$">\s*Header set Content-Security-Policy "default-src \'none\';[^"]*sandbox"~', $htaccess), $file);
    }
});

test('Привязка Telegram без пароля не проходит', function (): void {
    $method = new ReflectionMethod(\App\Core\TelegramLink::class, 'confirm');
    $params = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());

    assert_same(['userId', 'password'], $params);
});

