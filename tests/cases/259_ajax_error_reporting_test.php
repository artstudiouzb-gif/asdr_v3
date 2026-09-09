<?php

declare(strict_types=1);

use App\Core\ErrorHandler;

// Регрессия: исключение в AJAX-эндпоинте админки отдавалось HTML-страницей 500,
// и редактор видел только «Сервер вернул некорректный ответ» — без причины.
// Теперь такой запрос получает JSON с человеческим объяснением, тем же, что
// пишется в «Журнал ошибок».

test('Ошибка в AJAX-запросе возвращается JSON-ом с понятным объяснением', function () {
    $method = new ReflectionMethod(ErrorHandler::class, 'renderJsonError');

    ob_start();
    $method->invoke(null, new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
    $body = (string) ob_get_clean();

    $payload = json_decode($body, true);
    assert_true(is_array($payload), 'ответ — валидный JSON');
    assert_false((bool) ($payload['ok'] ?? true));
    assert_contains('базе данных', (string) ($payload['error'] ?? ''), 'объяснение то же, что в журнале ошибок');
    assert_not_contains('SQLSTATE', (string) ($payload['error'] ?? ''), 'технические детали наружу не уходят');
});

test('JSON отдаётся только запросам, которые его ждут', function () {
    $method = new ReflectionMethod(ErrorHandler::class, 'wantsJson');

    $server = $_SERVER;
    try {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
        assert_false((bool) $method->invoke(null), 'обычная страница получает HTML-заглушку 500');

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        assert_true((bool) $method->invoke(null));

        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
        assert_true((bool) $method->invoke(null));
    } finally {
        $_SERVER = $server;
    }
});

test('Импортёр новостей объясняет и не-JSON ответ сервера', function () {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-news-import.js');

    assert_contains('function describeBadResponse', $js, 'у обрыва ответа есть разбор с кодом HTTP');
    assert_contains('response.status', $js, 'в сообщении виден код ответа');
    // Ошибку по таймеру не гасим: в ней написано, что делать дальше.
    assert_not_contains('errorToast.hidden = true; }, 9000', $js);
    assert_contains('function clearError', $js, 'старая ошибка снимается при следующем действии');
});
