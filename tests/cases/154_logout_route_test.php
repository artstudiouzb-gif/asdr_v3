<?php

declare(strict_types=1);

test('Выход разрешён только POST-запросом и защищён CSRF', function (): void {
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    $controller = (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Controllers/Admin/AuthController.php'
    );
    $toolbar = (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Views/site/components/admin_toolbar.php'
    );

    assert_not_contains(
        "\$router->get('/admin/logout', [AdminAuthController::class, 'logout']);",
        $routes,
        'GET не должен завершать сессию'
    );
    assert_contains(
        "\$router->post('/admin/logout', [AdminAuthController::class, 'logout']);",
        $routes,
        'POST-маршрут зарегистрирован'
    );
    assert_contains('Csrf::verifyRequest();', $controller, 'выход защищён CSRF');
    assert_contains('Auth::logout();', $controller, 'сессия очищается');
    assert_contains("header('Location: /admin/login');", $controller, 'после выхода есть редирект');
    assert_contains('method="post" action="/admin/logout"', $toolbar, 'публичный тулбар использует POST');
    assert_contains('name="csrf_token"', $toolbar, 'публичный тулбар передаёт CSRF-токен');
});
