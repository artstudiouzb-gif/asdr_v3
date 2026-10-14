<?php

declare(strict_types=1);

use App\Models\MenuItem;

// Своя иконка пункта меню уходит в src шапки на каждой странице: принимается
// только картинка своим путём или по https, без схем и кавычек.
test('Menu icon image: only image paths are accepted', function (): void {
    assert_same('/uploads/public/gerb.svg', MenuItem::cleanIconImage(' /uploads/public/gerb.svg '));
    assert_same('/uploads/public/logo.png?v=2', MenuItem::cleanIconImage('/uploads/public/logo.png?v=2'));
    assert_same('https://cdn.example.uz/i.webp', MenuItem::cleanIconImage('https://cdn.example.uz/i.webp'));
    foreach ([
        '', null, ['x'], 'javascript:alert(1)//.svg', '//evil.example/x.svg', 'http://x.uz/a.png',
        '/uploads/a.php', '/uploads/a".svg', '/uploads/a b.svg', 'data:image/svg+xml,<svg>.svg',
    ] as $bad) {
        assert_same(null, MenuItem::cleanIconImage($bad), 'принято: ' . var_export($bad, true));
    }
});

// Размер — любое целое в границах: в шапку он уходит атрибутом style, и
// строка туда попадать не должна.
test('Menu icon size: integer clamped to bounds, empty means theme size', function (): void {
    assert_same(80, MenuItem::cleanIconSize('80'));
    assert_same(0, MenuItem::cleanIconSize(''));
    assert_same(0, MenuItem::cleanIconSize('80px;color:red'));
    assert_same(0, MenuItem::cleanIconSize(['24']));
    assert_same(MenuItem::ICON_SIZE_MIN, MenuItem::cleanIconSize(3));
    assert_same(MenuItem::ICON_SIZE_MAX, MenuItem::cleanIconSize(9999));
});
