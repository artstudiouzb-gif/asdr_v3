<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\SecretBox;
use App\Models\Setting;

test('SecretBox: authenticated encryption, context и старый ключ', function () {
    $first = str_repeat('12', 32);
    $second = str_repeat('34', 32);
    Config::merge(['crypto' => ['encryption_key' => $first, 'previous_encryption_key' => '']]);

    $encrypted = SecretBox::encrypt('очень-секретно', 'tests.value');
    assert_true(str_starts_with($encrypted, 'enc:v1:'));
    assert_false(str_contains($encrypted, 'очень-секретно'));
    assert_same('очень-секретно', SecretBox::decrypt($encrypted, 'tests.value'));

    $wrongContextFailed = false;
    try {
        SecretBox::decrypt($encrypted, 'tests.other');
    } catch (RuntimeException) {
        $wrongContextFailed = true;
    }
    assert_true($wrongContextFailed, 'контекст участвует в аутентификации');

    Config::merge(['crypto' => ['encryption_key' => $second, 'previous_encryption_key' => $first]]);
    assert_same('очень-секретно', SecretBox::decrypt($encrypted, 'tests.value'), 'старый ключ читается при ротации');
    assert_same('legacy-plain', SecretBox::decrypt('legacy-plain', 'tests.value'), 'старое открытое значение читается до миграции');

    Config::merge(['crypto' => ['encryption_key' => str_repeat('11', 32), 'previous_encryption_key' => '']]);
});

test('Setting: API-токен хранится зашифрованным и читается открытым', function (): void {
    if (!Database::isConnected()) {
        return;
    }
    $token = 'token-' . bin2hex(random_bytes(8));
    Setting::set('cf_api_token', $token);
    try {
        $stmt = Database::pdo()->prepare('SELECT `value` FROM settings WHERE `key` = :key');
        $stmt->execute([':key' => 'cf_api_token']);
        $raw = (string) $stmt->fetchColumn();
        assert_true(str_starts_with($raw, 'enc:v1:'));
        assert_false(str_contains($raw, $token));
        assert_same($token, Setting::get('cf_api_token'));
    } finally {
        Setting::set('cf_api_token', '');
    }
});
