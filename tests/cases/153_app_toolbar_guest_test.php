<?php

declare(strict_types=1);

use App\Core\AppToolbar;

test('AppToolbar: для гостей (когда разлогинен) админ-бар НЕ выводится и класс has-admin-bar НЕ добавляется', function (): void {
    // Гарантируем, что сессия очищена (гость)
    $_SESSION = [];

    $output = AppToolbar::renderHtml([]);

    // Проверяем, что для гостя нет разметки админ-панели
    assert_true(strpos($output, 'app-admin-bar') === false, 'Гость не получает HTML админ-панели');
    assert_true(strpos($output, 'has-admin-bar') === false, 'Гость не получает класс margin-top для body');
    assert_true(strpos($output, '<script') === false, 'Гость не получает inline-скрипты');
});
