<?php

declare(strict_types=1);

test('POST /admin/pages/{id}/make-home назначает страницу Главной и сбрасывает прошлые', function (): void {
    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    $controller = (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Controllers/Admin/PageController.php'
    );

    assert_contains(
        "\$router->post('/admin/pages/{id}/make-home', [AdminPageController::class, 'makeHome']);",
        $routes,
        'POST-маршрут зарегистрирован'
    );
    assert_contains('Csrf::verifyRequest();', $controller, 'операция защищена CSRF');
    assert_contains('UPDATE pages SET is_home = 0', $controller, 'предыдущая главная сбрасывается');
    assert_contains("UPDATE pages SET is_home = 1, status = 'published'", $controller);
});
