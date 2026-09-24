<?php

declare(strict_types=1);

use App\Core\AdminBrand;
use App\Models\Setting;

/**
 * Ассеты админки собираются в бандлы (scripts/build-assets.mjs). Каскад панели
 * держится на порядке файлов, поэтому проверяется не «файл подключён», а
 * порядок: внутри бандла (манифест) и между бандлами (шапка и подвал).
 */
function admin_bundle_sources(string $bundle): array
{
    $manifest = json_decode((string) file_get_contents(APP_ROOT . '/public/assets/asset-manifest.json'), true);
    assert_true(is_array($manifest) && isset($manifest['admin'][$bundle]), "бандл {$bundle} есть в манифесте");

    return array_map('basename', $manifest['admin'][$bundle]['sources']);
}

test('Бандлы админки собраны из файлов в прежнем порядке', function (): void {
    assert_same(['admin.css'], admin_bundle_sources('/assets/admin/admin-core.min.css'));
    assert_same(['admin-shell-v2.css'], admin_bundle_sources('/assets/admin/admin-shell.min.css'));
    assert_same(
        ['admin-notifications.css', 'admin-shell-stability.css', 'admin-hero-slide-editor.css'],
        admin_bundle_sources('/assets/admin/admin-brand.min.css'),
        'слои AdminBrand: стабильность оболочки перекрывает стили уведомлений'
    );
    assert_same(
        ['admin-workflow-fixes.css', 'admin-media-unified.css', 'admin-slider-settings-layout.css'],
        admin_bundle_sources('/assets/admin/admin-panel.min.css'),
        'слои панели идут в том порядке, в каком их добавляла цепочка загрузки'
    );
    assert_same(
        [
            'admin.js',
            'admin-media-bridge.js',
            'admin-workflow-fixes.js',
            'admin-slider-settings-layout.js',
            'admin-gallery-dropzone.js',
            'admin-media-loadmore.js',
        ],
        admin_bundle_sources('/assets/admin/admin.min.js'),
        'admin.js первым: остальные слои надстраивают его медиабиблиотеку'
    );

    foreach (['admin-core.min.css', 'admin-shell.min.css', 'admin-brand.min.css', 'admin-panel.min.css', 'admin.min.js'] as $file) {
        assert_true(is_file(APP_ROOT . '/public/assets/admin/' . $file), "{$file} собран и лежит в репозитории");
    }
});

test('Шапка и подвал админки подключают бандлы, а не исходники и не загрузчик', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/footer.php');

    $core = strpos($header, '/assets/admin/admin-core.min.css');
    $shell = strpos($header, '/assets/admin/admin-shell.min.css');
    $brand = strpos($header, 'AdminBrand::styleTag()');
    $panel = strpos($header, '/assets/admin/admin-panel.min.css');
    assert_true($core !== false && $shell !== false && $brand !== false && $panel !== false, 'все бандлы подключены');
    assert_true($core < $shell && $shell < $brand && $brand < $panel, 'ядро → оболочка → слои AdminBrand → слои панели');
    assert_not_contains("'/assets/css/admin.css'", $header);

    assert_contains("Asset::url('/assets/admin/admin.min.js')", $footer);
    assert_not_contains("'/assets/js/admin.js'", $footer, 'исходник не грузится поверх бандла');
    assert_false(is_file(APP_ROOT . '/public/assets/js/admin-media-loader.js'), 'цепочечного загрузчика больше нет');

    $asset = (string) file_get_contents(APP_ROOT . '/app/Core/Asset.php');
    assert_not_contains('admin-media-loader', $asset, 'Asset::url не подменяет адрес молча');

    foreach (glob(APP_ROOT . '/app/Views/admin/auth/*.php') ?: [] as $view) {
        $html = (string) file_get_contents($view);
        if (str_contains($html, 'AdminBrand::styleTag()')) {
            assert_contains('/assets/admin/admin-core.min.css', $html, basename($view) . ': экран входа берёт тот же файл ядра, что и панель');
        }
    }
});

test('AdminBrand подключает свои слои одним файлом до клиента уведомлений', function (): void {
    Setting::set('admin_brand_accent', '');
    $head = AdminBrand::styleTag();

    $css = strpos($head, '/assets/admin/admin-brand.min.css');
    $js = strpos($head, 'admin-notifications.js');
    assert_true($css !== false && $js !== false, 'стили и клиент подключены');
    assert_true($css < $js, 'стили объявлены до отложенного клиента');
    assert_contains('data-admin-notifications-css="1"', $head, 'по метке клиент понимает, что стили уже есть, и не грузит их второй раз');
});
