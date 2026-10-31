<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Uploader;
use App\Core\UrlGuard;

/*
 * Очередь 5 плана улучшений (docs/IMPROVEMENT_PLAN_2026-10.md): системные
 * меры безопасности — проверки, которые не зависят от памяти автора действия.
 */

test('Панель: вход требует роутер, открыты только вход и восстановление пароля', function (): void {
    foreach (['/admin', '/admin/users', '/admin/backup/download', '/admin/new-section'] as $path) {
        assert_true(Router::requiresLogin($path), 'закрыто: ' . $path);
    }
    foreach (['/admin/login', '/admin/login/2fa', '/admin/login/2fa/resend', '/admin/logout',
        '/admin/forgot', '/admin/reset', '/admin/reset/abc123'] as $path) {
        assert_false(Router::requiresLogin($path), 'открыто: ' . $path);
    }
    foreach (['/', '/news', '/administration', '/repo/login'] as $path) {
        assert_false(Router::requiresLogin($path), 'не панель: ' . $path);
    }
});

test('Панель: аноним уходит на вход, действие не вызывается', function (): void {
    $script = sprintf(
        'require %s; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/admin/forgotten-check";'
        . '$r = new App\\Core\\Router(); $r->get("/admin/forgotten-check", static function (): void { echo "HANDLER"; });'
        . '$r->dispatch("GET", "/admin/forgotten-check");',
        var_export(APP_ROOT . '/tests/bootstrap.php', true)
    );
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $out);
    assert_not_contains('HANDLER', implode("\n", $out), 'действие без проверки входа анониму не отвечает');
});

test('Адрес с вложенным IPv4 проверяется по вложенному адресу', function (): void {
    foreach (['::ffff:127.0.0.1', '::ffff:10.0.0.5', '64:ff9b::a00:1', '::127.0.0.1',
        '2002:7f00:1::1', '100.64.0.1', '198.18.0.1', '::1', '127.0.0.1'] as $ip) {
        assert_false(UrlGuard::isPublicIp($ip), 'внутренний: ' . $ip);
    }
    foreach (['8.8.8.8', '::ffff:8.8.8.8', '2001:4860:4860::8888'] as $ip) {
        assert_true(UrlGuard::isPublicIp($ip), 'публичный: ' . $ip);
    }
});

test('Загрузка: шрифт и аудио сверяются по подписи, а не по расширению', function (): void {
    $dir = sys_get_temp_dir() . '/sig-' . bin2hex(random_bytes(3));
    mkdir($dir);
    $cases = [
        ['woff2', 'wOF2' . str_repeat("\0", 20), true],
        ['woff', 'wOFF' . str_repeat("\0", 20), true],
        ['mp3', 'ID3' . str_repeat("\0", 20), true],
        ['mp3', "\xFF\xFB\x90\x00" . str_repeat("\0", 20), true],
        ['ogg', 'OggS' . str_repeat("\0", 20), true],
        ['wav', 'RIFF' . "\0\0\0\0" . 'WAVEfmt ', true],
        ['woff2', '<html><script>alert(1)</script></html>', false],
        ['mp3', '<?php echo 1; ?>', false],
    ];
    try {
        foreach ($cases as $i => [$ext, $body, $ok]) {
            $path = $dir . '/f' . $i . '.' . $ext;
            file_put_contents($path, $body);
            assert_same($ok, Uploader::mimeMatches($ext, 'application/octet-stream', $path), $ext . ' #' . $i);
        }
    } finally {
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
});

test('CSRF-токен после входа новый', function (): void {
    $saved = $_SESSION ?? [];
    try {
        $before = \App\Core\Csrf::token();
        \App\Core\Csrf::rotate();
        assert_true(\App\Core\Csrf::token() !== $before, 'токен анонимной сессии после входа не годится');
    } finally {
        $_SESSION = $saved;
    }
});
