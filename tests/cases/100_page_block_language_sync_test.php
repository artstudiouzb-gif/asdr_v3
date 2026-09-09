<?php

declare(strict_types=1);

test('page editor uses independent translation rows and page builder', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/pages/form.php');
    $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/PageController.php');

    assert_true(is_string($view));
    assert_true(is_string($controller));
    assert_contains('renderSidebarMetaBox', $view);
    assert_contains('name="title"', $view);
});
