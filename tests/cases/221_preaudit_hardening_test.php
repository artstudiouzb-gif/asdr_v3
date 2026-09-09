<?php

declare(strict_types=1);

test('Pre-audit: fail-safe health response не раскрывает внутренние детали', function (): void {
    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    $start = strpos($bootstrap, "if (\$failedPath === '/health')");
    assert_true($start !== false, 'ветка fail-safe health существует');

    $snippet = substr($bootstrap, (int) $start, 1000);
    assert_contains("json_encode(['status' => 'down']", $snippet, 'наружу выходит только общий статус');
    assert_not_contains("'checks' =>", $snippet, 'структура внутренних проверок скрыта');
    assert_not_contains('Release::id()', $snippet, 'идентификатор релиза не раскрывается');
    assert_not_contains("'workers' =>", $snippet, 'состояние воркеров не раскрывается');
});

test('Pre-audit: forwarded headers очищаются до security headers', function (): void {
    $bootstrap = (string) file_get_contents(APP_ROOT . '/app/Core/bootstrap.php');
    $requestUrl = (string) file_get_contents(APP_ROOT . '/app/Core/RequestUrl.php');

    assert_contains('ClientIp::isTrustedProxy($peer)', $bootstrap, 'peer проверяется по allowlist');
    assert_contains("'HTTP_X_FORWARDED_PROTO'", $bootstrap, 'поддельная схема очищается');
    assert_contains('ClientIp::applyTrustedProxy();', $bootstrap, 'доверенный proxy применяется централизованно');
    assert_contains('SecurityHeaders::send();', $bootstrap, 'заголовки выставляются после proxy policy');
    assert_contains('Permissions-Policy:', $bootstrap, 'опасные browser capabilities выключены');

    $configPos = strpos($bootstrap, 'Config::set($config);');
    $headersPos = strpos($bootstrap, '$prepareHttpSecurity();', (int) $configPos);
    $dbPos = strpos($bootstrap, 'Database::init($config[\'db\']);');
    assert_true($configPos !== false && $headersPos !== false && $dbPos !== false, 'контрольные точки найдены');
    assert_true($configPos < $headersPos && $headersPos < $dbPos, 'Config и proxy policy загружаются до БД');

    assert_contains('ClientIp::isTrustedProxy($peer)', $requestUrl, 'RequestUrl отдельно проверяет доверенный proxy');
});
