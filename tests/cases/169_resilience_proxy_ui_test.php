<?php

declare(strict_types=1);

use App\Core\ClientIp;
use App\Core\FileRateLimiter;

test('trusted proxy CIDR matching supports IPv4 and IPv6', function (): void {
    assert_true(ClientIp::inCidr('173.245.48.10', '173.245.48.0/20'));
    assert_false(ClientIp::inCidr('173.245.64.10', '173.245.48.0/20'));
    assert_true(ClientIp::inCidr('2001:db8::42', '2001:db8::/32'));
    assert_false(ClientIp::inCidr('2001:db9::42', '2001:db8::/32'));
    assert_false(ClientIp::inCidr('not-an-ip', '173.245.48.0/20'));
});

test('routine request limiter is atomic and does not require the database', function (): void {
    $identifier = 'test:' . bin2hex(random_bytes(12));

    try {
        assert_true(FileRateLimiter::allow($identifier, 2, 60) === true);
        assert_true(FileRateLimiter::allow($identifier, 2, 60) === true);
        assert_true(FileRateLimiter::allow($identifier, 2, 60) === false);
    } finally {
        FileRateLimiter::forget($identifier);
    }
});

test('request guard and trusted proxy resolution run before database startup', function (): void {
    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');

    // Ищем вызовы по имени метода, а не по строке с пустыми скобками:
    // `Database::init($config['db'])` принимает аргумент, и точное совпадение
    // «Database::init()» не находилось — тест молча ничего не проверял.
    $offsetOf = static function (string $call) use ($bootstrap): ?int {
        return preg_match('/' . preg_quote($call, '/') . '\s*\(/', $bootstrap, $m, PREG_OFFSET_CAPTURE) === 1
            ? (int) $m[0][1]
            : null;
    };

    $proxyPosition = $offsetOf('ClientIp::applyTrustedProxy');
    $guardPosition = $offsetOf('WafGuard::inspect');
    $databasePosition = $offsetOf('Database::init');

    assert_true($proxyPosition !== null, 'ClientIp::applyTrustedProxy не вызывается в bootstrap');
    assert_true($guardPosition !== null, 'WafGuard::inspect не вызывается в bootstrap');
    assert_true($databasePosition !== null, 'Database::init не вызывается в bootstrap');
    assert_true($proxyPosition < $databasePosition, 'доверенный прокси разбирается до подключения к БД');
    assert_true($guardPosition < $databasePosition, 'WAF проверяет запрос до подключения к БД');
    assert_contains("if (\$failedPath === '/health')", $bootstrap);
    assert_contains("'status' => 'down'", $bootstrap, 'аварийный health сохраняет минимальный статус');
    assert_not_contains("'release' => \\App\\Core\\Release::id()", $bootstrap, 'release не раскрывается без токена');
    assert_not_contains("'workers' =>", $bootstrap, 'состояние воркеров не раскрывается в fail-safe');
});

test('search load is bounded and repeated requests are cached', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/SearchController.php');

    assert_contains('mb_substr(', $controller);
    assert_contains('Cache::remember(', $controller);
    assert_contains("'search:' . Locale::current()", $controller);
    assert_contains("'search-suggest:' . Locale::current()", $controller);
});

test('mobile controls meet coarse pointer target size and notices are announced', function (): void {
    $publicCss = theme_css();
    $adminCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    $siteHeader = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $adminHeader = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    $repoHeader = (string) file_get_contents(APP_ROOT . '/app/Views/repo/layout/top.php');

    assert_contains('@media (pointer: coarse)', $publicCss);
    assert_contains('.site-search-toggle', $publicCss);
    assert_contains('min-height: 44px', $publicCss);
    assert_contains('@media (pointer: coarse)', $adminCss);
    assert_contains('.art-editor__btn', $adminCss);
    assert_contains("? 'assertive' : 'polite'", $siteHeader);
    assert_contains("? 'assertive' : 'polite'", $adminHeader);
    assert_contains("? 'polite' : 'assertive'", $repoHeader);
});

test('release smoke check detects stale production code and critical CSS failures', function (): void {
    $release = (string) file_get_contents(APP_ROOT . '/scripts/release.php');
    $smoke = (string) file_get_contents(APP_ROOT . '/scripts/smoke.php');

    assert_contains("'--expect-release'", $release);
    assert_contains('/assets/css/system.css', $smoke);
    assert_contains('$expectedRelease', $smoke);
});
