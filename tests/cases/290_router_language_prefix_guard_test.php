<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Router;

/*
 * Языковой префикс не создаёт второго адреса служебным областям.
 *
 * Найдено адверсариальным ревью. Активный не-дефолтный язык даёт префикс, и
 * `/uz/admin/users` доезжал до настоящего обработчика `/admin/users`:
 * `Router::resolveLocale()` срезает `/uz`, а `LocalePreference::managesPath()`
 * смотрит на путь запроса, под её исключения `/uz/admin/...` не подпадает.
 *
 * Опасность была не в самом маршруте — контроллеры защищены `requireLogin`
 * и `requireSuperAdmin`, — а в проверках, которые стоят ДО роутера и сверяют
 * путь запроса строкой `str_starts_with($path, '/admin')':
 *   1) онбординг второго фактора (public/index.php): сессия с
 *      `2fa_setup_required` должна видеть только профиль, а через
 *      `/uz/admin/...` получала всю панель (живая проверка доходила до
 *      создания супер-админа);
 *   2) `AdminEntryGate` — скрытый адрес панели и IP-allowlist вместе с ним:
 *      `/admin/login` отдавал 404, `/uz/admin/login` — форму входа.
 *
 * Проверка живёт в роутере, в единственном месте, где префикс снимается:
 * список проверок до роутера ещё вырастет, и каждая повторяла бы ошибку.
 * Служебной области локализованной версии не положено, поэтому ответ — 404.
 */

/** Прогоняет путь через настоящий роутер и говорит, дошёл ли он до обработчика. */
function router_prefix_probe(string $registerPattern, string $requestPath): bool
{
    $savedServer = $_SERVER;
    $savedCookie = $_COOKIE;
    $GLOBALS['__prefix_probe_hit'] = false;

    try {
        $router = new Router();
        $router->get($registerPattern, static function (): void {
            $GLOBALS['__prefix_probe_hit'] = true;
        });

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REQUEST_URI'] = $requestPath;
        $router->dispatch('GET', $requestPath);
    } finally {
        $_SERVER = $savedServer;
        $_COOKIE = $savedCookie;
    }

    return (bool) ($GLOBALS['__prefix_probe_hit'] ?? false);
}

/** @return array{0:string,1:string}|null код второго языка и код основного */
function router_prefix_langs(): ?array
{
    $db = getenv('TEST_DB_DATABASE');
    if ($db === false || $db === '') {
        return null;
    }

    Database::init([
        'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('TEST_DB_PORT') ?: '3306',
        'database' => $db,
        'username' => getenv('TEST_DB_USERNAME') ?: 'root',
        'password' => getenv('TEST_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ]);

    $default = \App\Models\Language::defaultCode();
    foreach (\App\Models\Language::activeCodes() as $code) {
        if ($code !== $default) {
            return [$code, $default];
        }
    }

    return null;
}

test('Языковой префикс не протаскивает запрос в служебные области (БД)', function () {
    $langs = router_prefix_langs();
    if ($langs === null) {
        skip_test('Нужны TEST_DB_* и второй активный язык');
    }
    [$secondary, $default] = $langs;

    try {
        // Весь список исключений `LocalePreference::managesPath()`, а не один
        // `/admin`: `/repo` — свой вход в файловый портал, `/install` — мастер
        // установки, остальные машинные ответы не должны двоиться в поиске.
        $areas = [
            '/admin/__probe',
            '/repo/__probe',
            '/install/__probe',
            '/health',
            '/sitemap.xml',
            '/robots.txt',
        ];

        foreach ($areas as $area) {
            $attack = '/' . $secondary . $area;
            assert_false(
                router_prefix_probe($area, $attack),
                "запрос {$attack} дошёл до обработчика {$area}: языковой префикс обошёл проверки, которые стоят до роутера"
            );
        }
    } finally {
        \App\Core\Locale::set($default);
        \App\Core\Locale::setPath('/');
    }
});

test('Обычная страница по языковому префиксу по-прежнему открывается (БД)', function () {
    $langs = router_prefix_langs();
    if ($langs === null) {
        skip_test('Нужны TEST_DB_* и второй активный язык');
    }
    [$secondary, $default] = $langs;

    try {
        // Обратная сторона проверки: закрывая служебные области, легко закрыть
        // заодно и публичные страницы. Тогда сайт остался бы одноязычным, и
        // заметили бы это не здесь, а на сайте.
        assert_true(
            router_prefix_probe('/news', '/' . $secondary . '/news'),
            "запрос /{$secondary}/news не дошёл до обработчика /news: сломан публичный языковой префикс"
        );
        assert_same($secondary, \App\Core\Locale::current(), 'язык из префикса не установлен');
    } finally {
        \App\Core\Locale::set($default);
        \App\Core\Locale::setPath('/');
    }
});
