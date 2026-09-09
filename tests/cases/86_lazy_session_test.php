<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Session;

test('Сессия определяется по настроенному cookie без её запуска', function () {
    $cookies = $_COOKIE;
    $sessionConfig = [
        'name' => (string) Config::get('session.name', 'asc_session'),
        'lifetime' => (int) Config::get('session.lifetime', 7200),
    ];
    try {
        Config::merge(['session' => ['name' => 'test_session', 'lifetime' => 7200]]);
        $_COOKIE = [];
        assert_false(Session::hasCookie());
        $_COOKIE['test_session'] = 'existing-id';
        assert_true(Session::hasCookie());
    } finally {
        $_COOKIE = $cookies;
        Config::merge(['session' => $sessionConfig]);
    }
});

test('Bootstrap не запускает новую сессию каждому публичному посетителю', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    assert_contains('Session::hasCookie()', $source);
    assert_contains('Session::start()', $source);
    assert_not_contains('session_start();', $source);
});

test('CSRF, Flash, Auth и CAPTCHA запускают сессию по требованию', function () {
    foreach (['Csrf.php', 'Flash.php', 'Auth.php', 'Captcha.php'] as $file) {
        $source = (string) file_get_contents(APP_ROOT . '/app/Core/' . $file);
        assert_contains('Session::start();', $source, $file);
    }
});

test('Шаблоны буферизуются до установки ленивого session cookie', function () {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/View.php');
    assert_contains('ob_start();', $source);
    assert_contains('ob_get_clean()', $source);
});

test('Web Push получает CSRF только после действия пользователя', function () {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/PushController.php');
    $javascript = (string) file_get_contents(APP_ROOT . '/public/assets/js/push.js');

    assert_not_contains('meta name="csrf-token"', $header);
    assert_contains("'csrf_token' => Csrf::token()", $controller);
    assert_contains("fetch('/push/key')", $javascript);
    assert_contains('config.csrf_token', $javascript);
});
