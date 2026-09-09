<?php

declare(strict_types=1);

use App\Core\AdminBrand;
use App\Models\Setting;

// White-label админки: название, логотип, акцент, производные оттенки.

test('AdminBrand: значения по умолчанию', function () {
    Setting::set('admin_brand_name', '');
    Setting::set('admin_brand_logo', '');
    Setting::set('admin_brand_accent', '');

    assert_same('ASDR', AdminBrand::name());
    assert_same('A', AdminBrand::letter());
    assert_true(AdminBrand::logo() === null, 'логотип не задан');
    assert_same('#2271b1', AdminBrand::accent());

    $head = AdminBrand::styleTag();
    assert_not_contains('<style>', $head, 'при стандартном акценте инлайн-стилей нет');
    assert_contains('admin-notifications.css', $head, 'внешние стили Центра уведомлений подключены');
    assert_contains('admin-notifications.js', $head, 'клиент Центра уведомлений подключён');
});

test('AdminBrand: свои название и буква бейджа', function () {
    Setting::set('admin_brand_name', 'Агентство');
    assert_same('Агентство', AdminBrand::name());
    assert_same('А', AdminBrand::letter());
    Setting::set('admin_brand_name', '');
});

test('AdminBrand: свой акцент рождает переменные с оттенками', function () {
    Setting::set('admin_brand_accent', '#17999B');
    assert_same('#17999b', AdminBrand::accent(), 'hex нормализован в нижний регистр');

    $css = AdminBrand::styleTag();
    assert_contains('--admin-accent:#17999b;', $css);
    assert_contains('--admin-accent-hover:#148485;', $css); // темнее на 14%
    assert_contains('--admin-accent-soft:#e8f5f5;', $css);  // 90% к белому
    assert_contains('--admin-accent-2:#33a5a7;', $css);     // светлее на 12%
    Setting::set('admin_brand_accent', '');
});

test('AdminBrand: мусорный акцент откатывается к стандартному', function () {
    Setting::set('admin_brand_accent', 'red;}body{display:none');
    assert_same('#2271b1', AdminBrand::accent());

    $head = AdminBrand::styleTag();
    assert_not_contains('<style>', $head, 'невалидный акцент не создаёт инлайн-CSS');
    assert_contains('admin-notifications.css', $head, 'безопасные внешние ассеты сохраняются');
    assert_contains('admin-notifications.js', $head, 'безопасный клиент сохраняется');
    Setting::set('admin_brand_accent', '');
});

test('AdminBrand::badgeHtml: картинка при логотипе, буква без него', function () {
    Setting::set('admin_brand_logo', '/uploads/public/brand".svg');
    $html = AdminBrand::badgeHtml();
    assert_contains('<img src="/uploads/public/brand&quot;.svg"', $html, 'URL экранирован');
    assert_contains('class="admin-topbar__logoimg"', $html);

    Setting::set('admin_brand_logo', '');
    Setting::set('admin_brand_name', '<b>X</b>');
    $html = AdminBrand::badgeHtml('i', 'auth-brand__logo');
    assert_contains('class="auth-brand__logo"', $html);
    assert_contains('&lt;', $html, 'буква экранирована');
    Setting::set('admin_brand_name', '');
});

test('AdminBrand::faviconHtml: вывод стандартов и кастомной фавиконки', function () {
    Setting::set('favicon_url', '');
    $html = AdminBrand::faviconHtml();
    // Ссылка появляется только для файла, который лежит в public/: иначе
    // админка на каждой странице получала 404.
    foreach (['/apple-touch-icon.png', '/favicon-32x32.png', '/favicon-16x16.png'] as $icon) {
        assert_same(
            is_file(dirname(__DIR__, 2) . '/public' . $icon),
            str_contains($html, 'href="' . $icon . '"'),
            "ссылка на {$icon} без файла"
        );
    }

    Setting::set('favicon_url', '/uploads/public/custom-fav.svg');
    $htmlWithCustom = AdminBrand::faviconHtml();
    assert_contains('href="/uploads/public/custom-fav.svg"', $htmlWithCustom);
    assert_contains('type="image/svg+xml"', $htmlWithCustom);
    Setting::set('favicon_url', '');
});
