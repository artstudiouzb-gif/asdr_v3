<?php

declare(strict_types=1);

use App\Core\InstallToken;

/*
 * Одноразовый токен установщика: пока сайт не установлен, /install открыт
 * всем, и без токена базу и администратора указал бы первый, кто дошёл до
 * страницы на свежем сервере.
 */

function install_token_tmp(): string
{
    $dir = sys_get_temp_dir() . '/asdr-install-token-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir . '/install.token';
}

test('Токен установщика создаётся один раз, закрыт от чужих и сверяется целиком', function (): void {
    $path = install_token_tmp();
    assert_true(InstallToken::ensure($path), 'файл токена должен создаться');
    $token = trim((string) file_get_contents($path));
    assert_true(preg_match('/^[0-9a-f]{32}$/', $token) === 1, '128 бит в hex');
    assert_same(0600, fileperms($path) & 0777, 'файл читает только владелец');

    assert_true(InstallToken::ensure($path));
    assert_same($token, trim((string) file_get_contents($path)), 'повторный заход не меняет токен');

    assert_true(InstallToken::verify($token, $path));
    assert_true(InstallToken::verify('  ' . strtoupper($token) . "\n", $path), 'пробелы и регистр при копировании не мешают');
    assert_true(!InstallToken::verify(substr($token, 0, 31), $path), 'часть токена не подходит');
    assert_true(!InstallToken::verify('', $path), 'пустой ввод не подходит');

    InstallToken::remove($path);
    assert_true(!is_file($path), 'после установки файла нет');
    assert_true(!InstallToken::verify($token, $path), 'без файла не подходит ничего');
    @rmdir(dirname($path));
});

test('Испорченный файл токена не пускает никого', function (): void {
    $path = install_token_tmp();
    file_put_contents($path, "\n");
    assert_true(!InstallToken::verify('', $path));
    assert_true(InstallToken::ensure($path) === false || preg_match('/^[0-9a-f]{32}$/', trim((string) file_get_contents($path))) === 1);
    @unlink($path);
    @rmdir(dirname($path));
});

test('Экран токена объясняет, где взять токен, и не показывает его', function (): void {
    $html = render_view('app/Views/install/unlock.php', ['tokenReady' => true, 'error' => null]);
    assert_true(str_contains($html, 'storage/install.token'));
    assert_true(str_contains($html, 'name="install_token"'));
    assert_true(str_contains($html, 'action="/install"'));

    $blocked = render_view('app/Views/install/unlock.php', ['tokenReady' => false, 'error' => null]);
    assert_true(!str_contains($blocked, 'name="install_token"'), 'без файла формы нет — ввести нечего');
    assert_true(str_contains($blocked, 'нет прав на запись'));
});
