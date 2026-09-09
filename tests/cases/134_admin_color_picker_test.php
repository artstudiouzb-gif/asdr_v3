<?php

declare(strict_types=1);

test('Админка использует локальную единую систему выбора цвета', function () {
    $root = dirname(__DIR__, 2);
    $header = (string) file_get_contents($root . '/app/Views/admin/layout/header.php');
    $footer = (string) file_get_contents($root . '/app/Views/admin/layout/footer.php');
    $adminJs = (string) file_get_contents($root . '/public/assets/js/admin.js');
    $adminCss = (string) file_get_contents($root . '/public/assets/css/admin.css');

    assert_contains('/assets/vendor/coloris/coloris.min.css', $header);
    assert_contains('/assets/vendor/coloris/coloris.min.js', $footer);
    assert_contains("input[type=\"color\"]", $adminJs);
    assert_contains("input.setAttribute('data-coloris'", $adminJs);
    assert_contains("format: 'hex'", $adminJs);
    assert_contains("alpha: false", $adminJs);
    assert_contains('.clr-field input[data-coloris]', $adminCss);

    assert_true(is_file($root . '/public/assets/vendor/coloris/coloris.min.css'));
    assert_true(is_file($root . '/public/assets/vendor/coloris/coloris.min.js'));
    assert_true(is_file($root . '/public/assets/vendor/coloris/LICENSE'));
});
