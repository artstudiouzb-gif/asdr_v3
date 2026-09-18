<?php

declare(strict_types=1);

use App\Core\RbacGuard;

/**
 * Право без потребителя — это запрет, которого не существует.
 *
 * Так жило `manage_trash`: оно было объявлено административным наравне с
 * `manage_users` и `manage_settings`, но не проверялось нигде, и роль `editor`
 * доходила до безвозвратного удаления страниц, новостей и проектов
 * (`Page::forceDelete()` — физический DELETE вместе с ревизиями). Отказ тихий:
 * матрица прав читается как действующая, раздел открывается, кнопка работает.
 */
function rbac_permission_names(): array
{
    $reflection = new ReflectionClass(RbacGuard::class);
    /** @var array<string, list<string>> $permissions */
    $permissions = $reflection->getConstant('PERMISSIONS');

    return array_keys($permissions);
}

/** Весь код приложения одной строкой: потребителя ищем и в контроллерах, и во вьюхах. */
function rbac_app_sources(): string
{
    $out = '';
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app'));
    foreach ($dir as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && !str_ends_with($file->getPathname(), 'RbacGuard.php')) {
            $out .= (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

test('RBAC: у каждого объявленного права есть потребитель или объяснение', function (): void {
    require_once __DIR__ . '/../budgets.php';

    $sources = rbac_app_sources();
    foreach (rbac_permission_names() as $permission) {
        if (str_contains($sources, "'" . $permission . "'")) {
            continue;
        }
        $reason = RBAC_PERMISSIONS_ENFORCED_ELSEWHERE[$permission] ?? '';
        assert_true(
            trim($reason) !== '',
            'Право ' . $permission . ' объявлено в RbacGuard, но нигде не проверяется: '
                . 'запрет существует только на бумаге. Либо примените его, либо объясните '
                . 'в RBAC_PERMISSIONS_ENFORCED_ELSEWHERE, чем он закрыт вместо этого.'
        );
    }
});

test('RBAC: объяснение требуется настоящее, а имя — существующее', function (): void {
    require_once __DIR__ . '/../budgets.php';

    $declared = rbac_permission_names();
    $sources = rbac_app_sources();
    foreach (RBAC_PERMISSIONS_ENFORCED_ELSEWHERE as $permission => $reason) {
        assert_true(
            in_array($permission, $declared, true),
            'Право ' . $permission . ' объяснено, но в RbacGuard его больше нет — уберите строку'
        );
        assert_true(
            !str_contains($sources, "'" . $permission . "'"),
            'Право ' . $permission . ' теперь проверяется в коде — объяснение лишнее'
        );
        assert_true(mb_strlen(trim($reason)) >= 20, 'Объяснение для ' . $permission . ' слишком короткое');
    }
});

test('RBAC: корзина закрыта от роли «редактор»', function (): void {
    // Удаление из корзины необратимо, поэтому проверяется каждое действие
    // раздела, а не только самое заметное.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/TrashController.php');
    assert_same(4, substr_count($controller, "RbacGuard::requirePermission('manage_trash')"));
    assert_same(
        substr_count($controller, 'Auth::requireLogin();'),
        substr_count($controller, "RbacGuard::requirePermission('manage_trash');"),
        'Каждое действие корзины обязано спрашивать право, а не только вход'
    );

    assert_false(RbacGuard::roleCan(RbacGuard::ROLE_EDITOR, 'manage_trash'));
    assert_true(RbacGuard::roleCan(RbacGuard::ROLE_ADMIN, 'manage_trash'));

    // Пункт меню спрашивает то же право: ссылка, отвечающая 403, читается как
    // поломка панели, а не как запрет.
    $nav = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    assert_contains("RbacGuard::can('manage_trash')", $nav);
});
