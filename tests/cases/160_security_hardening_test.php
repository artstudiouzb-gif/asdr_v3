<?php

declare(strict_types=1);

use App\Models\Setting;

test('Подключение к БД не запускает миграции автоматически', function (): void {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Database.php');
    assert_not_contains('runPendingMigrations', $source);
    assert_not_contains('MigrationRunner', $source);

    $migrator = (string) file_get_contents(APP_ROOT . '/database/migrate.php');
    $runner = (string) file_get_contents(APP_ROOT . '/app/Core/MigrationRunner.php');

    // Блокировка принадлежит единому runner, а CLI только делегирует ему
    // выполнение. Так установленный сайт не мигрирует БД на каждом запросе,
    // а clean-install и явный CLI используют один безопасный механизм.
    assert_contains('GET_LOCK', $runner);
    assert_contains('RELEASE_LOCK', $runner);
    assert_contains('MigrationRunner::applyPending', $migrator);
    assert_contains('Миграции остановлены', $migrator);
});

test('Все интеграционные пароли и API-ключи считаются секретами', function (): void {
    foreach ([
        'ai_api_key',
        'smtp_password',
        's3_access_key',
        's3_secret_key',
        'telegram_bot_token',
        'telegram_gateway_token',
    ] as $key) {
        assert_true(Setting::isSecret($key), $key . ' должен шифроваться');
    }
});

test('Критические действия имеют корректные лимиты и CSRF', function (): void {
    $portal = (string) file_get_contents(APP_ROOT . '/app/Controllers/Repo/PortalController.php');
    $repoAuth = (string) file_get_contents(APP_ROOT . '/app/Core/RepoAuth.php');
    $news = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NewsController.php');

    assert_contains("RateLimiter::throttle('repo_upload', (string) RepoAuth::id(), 10, 10, false)", $portal);
    assert_contains("RateLimiter::throttle('repo_2fa_resend', \$_SERVER['REMOTE_ADDR'] ?? 'unknown', 3, 5, false)", $repoAuth);
    assert_contains('Csrf::verifyRequest()', $news);
    assert_contains("RateLimiter::throttle('admin_ai_summary'", $news);
});

test('Вложения публичных форм сохраняются как защищённые', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/FormController.php');
    assert_contains("'protected'", $form);
    assert_contains('FileEntry::protectedUrl', $form);
    assert_not_contains("Uploader::store(\$file, 'public'", $form);
});
