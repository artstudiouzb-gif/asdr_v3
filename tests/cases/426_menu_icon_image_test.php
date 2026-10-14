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
