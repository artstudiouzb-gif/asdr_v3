<?php

declare(strict_types=1);

use App\Core\AppUrl;
use App\Core\Config;
use App\Core\RequestUrl;

test('Внешний HTTPS корректно определяется за доверенным reverse proxy', function () {
    $server = $_SERVER;
    $security = Config::get('security', []);
    $security = is_array($security) ? $security : [];

    try {
        Config::merge(['security' => array_merge($security, [
            'trusted_proxy_cidrs' => ['10.0.0.0/8'],
        ])]);
        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
        $_SERVER['HTTP_HOST'] = 'ArtStudio.UZ';

        assert_true(RequestUrl::isHttps());
        assert_same('https://artstudio.uz', RequestUrl::origin());
    } finally {
        $_SERVER = $server;
        Config::merge(['security' => $security]);
    }
});

test('Поддельный X-Forwarded-Proto от прямого клиента игнорируется', function () {
    $server = $_SERVER;
    $security = Config::get('security', []);
    $security = is_array($security) ? $security : [];

    try {
        Config::merge(['security' => array_merge($security, [
            'trusted_proxy_cidrs' => ['10.0.0.0/8'],
        ])]);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.25';
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_HOST'] = 'artstudio.uz';

        assert_false(RequestUrl::isHttps(), 'клиент не может выдать HTTP за HTTPS');
        assert_same('http://artstudio.uz', RequestUrl::origin());
    } finally {
        $_SERVER = $server;
        Config::merge(['security' => $security]);
    }
});

test('Некорректный Host не попадает в сгенерированные URL', function () {
    $server = $_SERVER;

    try {
        $_SERVER['HTTP_HOST'] = "artstudio.uz\r\nX-Injected: yes";
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        assert_same('http://localhost', RequestUrl::origin());
    } finally {
        $_SERVER = $server;
    }
});

test('Канонический URL повышает http до https без смены настроенного host', function () {
    assert_same(
        'https://artstudio.uz/cms',
        AppUrl::normalize('http://artstudio.uz/cms/', true)
    );
    assert_same(
        'http://artstudio.uz',
        AppUrl::normalize('http://artstudio.uz/', false)
    );
});

test('Публичные SEO и машинные ответы используют единый канонический URL', function () {
    $files = [
        '/app/Views/site/_header.php',
        '/app/Views/site/content_show.php',
        '/app/Views/site/news_show.php',
        '/app/Controllers/Site/NewsController.php',
        '/app/Controllers/Site/OpenDataController.php',
        '/app/Controllers/Site/SitemapController.php',
        '/app/Core/SocialSettings.php',
        '/app/Controllers/Admin/PasswordResetController.php',
        '/app/Controllers/Admin/NewsController.php',
        '/app/Console/push_worker.php',
        // Сборка ссылок дайджеста живёт в DigestDispatcher; digest_worker.php
        // остался тонкой CLI-обёрткой и адресов больше не строит.
        '/app/Core/DigestDispatcher.php',
    ];

    foreach ($files as $file) {
        $source = (string) file_get_contents(APP_ROOT . $file);
        assert_contains('AppUrl::base()', $source, $file);
    }

    // Подвал больше не собирает адрес сам, а переиспользует $canonicalUrl из
    // _header.php (там он и построен через AppUrl::base()). Требовать вызов
    // именно в подвале смысла нет — важно, что своей сборки адреса там нет.
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    assert_contains('$canonicalUrl', $footer, 'подвал использует канонический URL страницы');
    assert_false(
        preg_match('#https?://#', $footer) === 1,
        'в подвале не должно быть собственной сборки абсолютного адреса'
    );
});
