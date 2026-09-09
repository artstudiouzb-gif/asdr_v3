<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Media;

test('Hero отдаёт responsive picture и LCP preload для head', function (): void {
    $name = 'perf-hero-' . bin2hex(random_bytes(4));
    $disk = APP_ROOT . '/public/uploads/public/' . $name;
    $url = '/uploads/public/' . $name . '.jpg';
    file_put_contents($disk . '.jpg', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true));
    foreach (['.webp', '-800.webp', '-1600.webp'] as $suffix) {
        file_put_contents($disk . $suffix, 'test');
    }

    try {
        $rendered = BlockRenderer::renderPage([[
            'id' => 12001,
            'type' => 'hero',
            'data' => json_encode(['title' => 'LCP', 'bg_type' => 'image', 'image' => $url]),
            'custom_css' => '',
        ]]);

        assert_true(($rendered['preload_images'] ?? []) === [$url], 'Hero передаёт один preload-кандидат в head');
        assert_contains('<picture class="block-hero__media media-position--center-center', (string) $rendered['html']);
        assert_contains('class="block-hero__image media-position--center-center', (string) $rendered['html']);
        assert_contains('loading="eager"', (string) $rendered['html']);
        assert_contains('fetchpriority="high"', (string) $rendered['html']);
        assert_contains('srcset="/uploads/public/' . $name . '-800.webp 800w', (string) $rendered['html']);
        assert_true(!str_contains((string) $rendered['html'], ' width="1" height="1"'), 'Размеры файла не переопределяют высоту CSS-компонента');

        $preload = Media::preloadLink($url, '100vw');
        assert_contains('rel="preload"', $preload);
        assert_contains('imagesrcset=', $preload);
        assert_contains('fetchpriority="high"', $preload);
    } finally {
        @unlink($disk . '.jpg');
        foreach (['.webp', '-800.webp', '-1600.webp'] as $suffix) {
            @unlink($disk . $suffix);
        }
    }
});

test('Первый экран не блокируется второстепенным JS и заранее загружает LCP', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');

    assert_true(!str_contains($css, 'content-visibility: auto'), 'Секции не пропускаются при полноэкранном снимке и отрицательных отступах');
    assert_contains('FrontendAssets::scripts()', $footer);
    assert_contains('Asset::url($script), ENT_QUOTES) ?>" defer', $footer);
    assert_contains("Asset::url('/assets/js/consent.js')", $footer);
    assert_contains('Media::preloadLink', $header);
    assert_contains('rel="preconnect"', $header);
});
