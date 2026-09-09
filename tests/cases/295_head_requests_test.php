<?php

declare(strict_types=1);

use App\Core\Router;

/*
 * HEAD обслуживается теми же маршрутами, что и GET.
 *
 * Пока сравнение метода было строгим, HEAD не совпадал ни с одним маршрутом и
 * получал 404 на каждой странице. Заметить это трудно: браузер ходит GET'ом, а
 * HEAD'ом ходят мониторинги доступности (он дешевле) и часть краулеров — то
 * есть сайт отвечал «404» ровно тому, кто следит, жив ли он.
 *
 * Что поддержку писали, видно по остальному коду: кэш ответов, менеджер
 * редиректов и разрешение языка отдельно проверяют GET/HEAD.
 */

test('HEAD попадает в маршруты GET и не ломает остальные методы', function (): void {
    $hits = [];

    $router = new Router();
    $router->get('/head-probe', function () use (&$hits): void {
        $hits[] = 'get-route';
    });
    $router->post('/head-probe', function () use (&$hits): void {
        $hits[] = 'post-route';
    });

    ob_start();
    $router->dispatch('HEAD', '/head-probe');
    ob_end_clean();
    assert_same(['get-route'], $hits, 'HEAD обслужен маршрутом GET');

    $hits = [];
    ob_start();
    $router->dispatch('GET', '/head-probe');
    ob_end_clean();
    assert_same(['get-route'], $hits, 'GET по-прежнему свой');

    $hits = [];
    ob_start();
    $router->dispatch('POST', '/head-probe');
    ob_end_clean();
    assert_same(['post-route'], $hits, 'POST не задет: HEAD подменяет только сам себя');
});

test('Несуществующий адрес остаётся 404 и для HEAD', function (): void {
    $router = new Router();
    $router->get('/head-probe', static function (): void {
    });

    ob_start();
    $router->dispatch('HEAD', '/no-such-path-here');
    $body = (string) ob_get_clean();

    // Страница 404 рендерится — значит маршрут не найден, а не «нашёлся любой».
    assert_true($body !== '', 'на неизвестный адрес отвечает страница ошибки');
});

test('Публичная выборка новостей не спрашивает базу построчно', function (): void {
    // Условие «у группы уже есть запись нужного языка» сравнивало выражение
    // COALESCE(NULLIF(tgid,0), id) с таким же выражением внутри коррелированного
    // NOT EXISTS. Под такое сравнение не подходит никакой индекс: EXPLAIN
    // показывал DEPENDENT SUBQUERY с type=ALL — полный проход по news на каждую
    // строку-кандидата. Замерено на 409 новостях: /uz/news отвечала 155 мс
    // против 12 мс у русской версии, и рост был квадратичным.
    $model = (string) file_get_contents(APP_ROOT . '/app/Models/News.php');
    $start = strpos($model, 'private static function publicLanguageParts');
    assert_true($start !== false, 'сборщик языковой части выборки на месте');
    $end = strpos($model, 'private static function localizePublicRows', $start);
    $body = substr($model, $start, ($end !== false ? $end : strlen($model)) - $start);
    // Комментарии выбрасываем: в них эта конструкция названа по имени, и тест
    // ловил бы собственное объяснение вместо кода.
    $body = (string) preg_replace('#^\s*//.*$#m', '', $body);

    assert_true(
        !str_contains($body, 'NOT EXISTS'),
        'публичная выборка снова спрашивает базу построчно: верните производную таблицу'
    );
    assert_contains('SELECT DISTINCT COALESCE(NULLIF(translation_group_id, 0), id) AS group_key', $body);
    assert_contains('group_key IS NULL', $body, 'условие берётся из готовой таблицы групп');
});
