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

test('Публичный GET не поднимает сессию и с её cookie', function () {
    // Поднятая сессия делает ответ персональным: браузер не кеширует страницу
    // и каждый переход на главную идёт через сервер целиком. Её поднимают
    // сами формы, капча, вход и flash — когда она им нужна.
    assert_true(Session::deferrable('GET', '/'));
    assert_true(Session::deferrable('HEAD', '/news'));
    assert_true(Session::deferrable('GET', '/uz/news/some-slug'));

    // Служебные разделы читают $_SESSION напрямую, изменения — это POST.
    assert_false(Session::deferrable('POST', '/subscribe'));
    assert_false(Session::deferrable('GET', '/admin'));
    assert_false(Session::deferrable('GET', '/admin/pages'));
    assert_false(Session::deferrable('GET', '/repo/login'));
    assert_false(Session::deferrable('GET', '/install'));
    assert_false(Session::deferrable('GET', '/uz/search'));

    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    assert_contains('Session::deferrable(', $bootstrap);
});

test('Проверка второго фактора не поднимает сессию на публичной странице', function () {
    $index = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $start = strpos($index, "\$guardPath = parse_url");
    assert_true($start !== false);
    $guard = substr($index, (int) $start, 600);
    assert_true(
        strpos($guard, "str_starts_with(\$guardPath, '/admin')") < strpos($guard, 'Auth::check()'),
        'путь проверяется раньше входа: Auth::check() поднимает сессию'
    );
});

test('Панель администратора на сайте спрашивает вход только при метке', function () {
    $cookies = $_COOKIE;
    $session = $_SESSION ?? null;
    try {
        $_SESSION = [];
        $_COOKIE = [(string) \App\Core\Config::get('session.name', 'asc_session') => 'id-from-form'];
        assert_false(\App\Core\Auth::mightBeSignedIn(), 'cookie сессии после формы — ещё не вход');
        $_COOKIE[\App\Core\Auth::SIGNED_IN_COOKIE] = '1';
        assert_true(\App\Core\Auth::mightBeSignedIn(), 'метка входа — повод проверить сессию');
    } finally {
        $_COOKIE = $cookies;
        if ($session === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $session;
        }
    }

    $toolbar = (string) file_get_contents(APP_ROOT . '/app/Core/AppToolbar.php');
    assert_contains('Auth::mightBeSignedIn()', $toolbar);
    $auth = (string) file_get_contents(APP_ROOT . '/app/Core/Auth.php');
    foreach (['establishSession', 'logout'] as $method) {
        $at = strpos($auth, 'public static function ' . $method . '(');
        $body = substr($auth, (int) $at, 900);
        assert_contains('self::signedInMarker(', $body, $method . '() ставит или снимает метку входа');
    }
});

test('Flash поднимает сессию только при метке сообщения', function () {
    $flash = (string) file_get_contents(APP_ROOT . '/app/Core/Flash.php');
    assert_contains('MARKER_COOKIE', $flash);
    assert_contains('isset($_COOKIE[self::MARKER_COOKIE])', $flash, 'без метки шапка не поднимает сессию');
    assert_contains('self::marker(true)', $flash, 'сообщение ставит метку');
    assert_contains('self::marker(false)', $flash, 'показ снимает метку');
});
