<?php

declare(strict_types=1);

use App\Core\Icon;

test('Icon: валидирует ключи и рендерит локальный Tabler-спрайт', function (): void {
    assert_same('home', Icon::cleanName(' tabler-home '));
    assert_same('', Icon::cleanName('<svg><path/></svg>'));
    assert_same('', Icon::cleanName('home" onclick="alert(1)'));
    assert_same('', Icon::cleanName('definitely-not-a-tabler-icon'));
    assert_same('device-floppy', Icon::resolvedName('save'));

    $html = Icon::render('home', 20, 'test-icon', 1.8);
    assert_contains('class="icon icon-tabler test-icon"', $html);
    assert_contains('#tabler-home', $html);
    assert_contains('stroke-width="1.8"', $html);
    assert_not_contains('<path', $html, 'геометрия иконки должна находиться только в спрайте');
});

test('Локальный каталог Tabler синхронизирован и пригоден для пикера', function (): void {
    $catalogPath = APP_ROOT . '/public/assets/vendor/tabler/tabler-icons.json';
    $spritePath = APP_ROOT . '/public/assets/vendor/tabler/tabler-sprite.svg';

    assert_true(is_file($catalogPath), 'каталог иконок существует');
    assert_true(is_file($spritePath), 'SVG-спрайт существует');
    assert_true(is_file(APP_ROOT . '/public/assets/vendor/tabler/LICENSE'), 'лицензия пакета сохранена');

    $catalog = json_decode((string) file_get_contents($catalogPath), true);
    assert_true(is_array($catalog));
    assert_same('@tabler/icons-sprite', $catalog['source'] ?? '');
    assert_true(count($catalog['icons'] ?? []) > 5000, 'каталог содержит полный набор Tabler');
    assert_true(in_array('home', $catalog['icons'] ?? [], true));
    assert_true(in_array('brand-telegram', $catalog['icons'] ?? [], true));

    $adminJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains('asdr-icon-catalog', $adminJs);
    assert_contains('window.asdrTablerIcon', $adminJs);
    assert_contains('data-icon-picker-open', $adminJs);
});

test('UI-шаблоны не содержат собственную геометрию иконок и emoji', function (): void {
    $roots = [
        APP_ROOT . '/app/Views/install',
        APP_ROOT . '/app/Views/repo',
        APP_ROOT . '/app/Views/site',
        APP_ROOT . '/templates/blocks',
        APP_ROOT . '/templates/widgets',
    ];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            assert_not_contains('<svg', $source, $file->getPathname() . ': используйте Icon::render');
            assert_true(
                preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source) !== 1,
                $file->getPathname() . ': emoji не должны заменять UI-иконки'
            );
        }
    }

    $demo = (string) file_get_contents(APP_ROOT . '/database/demo_assets/home_blocks.json');
    $demoUz = (string) file_get_contents(APP_ROOT . '/database/demo_assets/home_blocks_uz.json');
    assert_not_contains('"icon_svg": "<svg', $demo);
    assert_contains('"icon_svg": "building"', $demo);
    assert_not_contains('"icon_svg": "<svg', $demoUz);
    assert_contains('"icon_svg": "building"', $demoUz);
});
