<?php

declare(strict_types=1);

use App\Core\AppToolbar;

function establish_toolbar_test_session(string $username = 'editor_user', string $role = 'editor'): void
{
    $_SERVER['HTTP_USER_AGENT'] = 'asdr-toolbar-test';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $userId = 1;

    // Сессия действительна, только пока её строка есть в реестре: Auth::check()
    // сверяется с ним и иначе разлогинивает. С появлением мгновенного
    // серверного отзыва сессий подделки массива $_SESSION стало недостаточно —
    // регистрируем её так же, как это делает настоящий вход. У user_sessions
    // есть внешний ключ на users, поэтому пользователь должен существовать:
    // соседние тесты (демо-данные, очистка) вычищают таблицу, и жёсткий id = 1
    // упирался в нарушение ограничения целостности.
    //
    // Без TEST_DB_* реестр недоступен; там Auth::check() ловит ошибку обращения
    // к базе, логирует и продолжает по фингерпринту, поэтому тест работает и в
    // прогоне без БД.
    if (\App\Core\Database::isConnected()) {
        $pdo = \App\Core\Database::pdo();
        $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, created_at)
             VALUES (:u, :e, :p, 'admin', NOW())
             ON DUPLICATE KEY UPDATE username = VALUES(username)"
        )->execute([
            ':u' => 'toolbar_test_user',
            ':e' => 'toolbar_test_user@example.test',
            ':p' => password_hash('toolbar-test', PASSWORD_DEFAULT),
        ]);

        $found = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $found->execute([':u' => 'toolbar_test_user']);
        $userId = (int) $found->fetchColumn();

        \App\Models\SessionRegistry::register($userId, session_id(), '127.0.0.1', 'asdr-toolbar-test');
    }

    $_SESSION = [
        'user_id' => $userId,
        'username' => $username,
        'role' => $role,
        'fingerprint' => hash('sha256', 'asdr-toolbar-test|127.0'),
    ];
}

test('Admin Topbar: для гостя не выводит разметку или inline-скрипты', function (): void {
    $_SESSION = [];

    $html = AppToolbar::renderHtml([]);
    assert_false(str_contains($html, 'id="app-admin-bar"'), 'Бар администратора скрыт для гостей');
    assert_same('', $html, 'Гость не получает служебную разметку');
});

test('Admin Topbar: для авторизованного администратора на странице выводит прямую ссылку редактирования страницы', function (): void {
    establish_toolbar_test_session();

    $context = [
        'page' => ['id' => 42, 'title' => 'Тестовая страница'],
    ];

    $html = AppToolbar::renderHtml($context);
    assert_true(str_contains($html, 'id="app-admin-bar"'), 'Панель администратора отображается');
    assert_true(str_contains($html, '/admin/pages/42/edit'), 'Содержит прямую ссылку на редактирование страницы 42');
    assert_true(str_contains($html, 'editor_user'), 'Содержит имя авторизованного пользователя');
    assert_true(str_starts_with($html, '<aside'), 'Панель размечена как вспомогательная область');
    assert_false(str_contains($html, '<script'), 'Панель не добавляет inline-скрипты');
});

test('Admin Topbar: на новости выводит прямую ссылку редактирования новости', function (): void {
    establish_toolbar_test_session();

    $context = [
        'news' => ['id' => 108, 'title' => 'Тестовая новость'],
    ];

    $html = AppToolbar::renderHtml($context);
    assert_true(str_contains($html, '/admin/news/108/edit'), 'Содержит прямую ссылку на редактирование новости 108');
});
