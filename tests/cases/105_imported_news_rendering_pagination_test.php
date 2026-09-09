<?php

declare(strict_types=1);

use App\Core\LegacyContentFormatter;

test('Legacy content: blank-line paragraphs are restored without touching editor HTML', function (): void {
    $legacy = "Birinchi xatboshi.\n\nIkkinchi xatboshi.\n\nUchinchi xatboshi.";
    $formatted = LegacyContentFormatter::paragraphize($legacy);

    assert_same(
        "<p>Birinchi xatboshi.</p>\n\n<p>Ikkinchi xatboshi.</p>\n\n<p>Uchinchi xatboshi.</p>",
        $formatted
    );

    $alreadyHtml = '<p>Редакторский абзац.</p><p>Второй абзац.</p>';
    assert_same($alreadyHtml, LegacyContentFormatter::paragraphize($alreadyHtml));

    $withBlock = "Kirish matni.\n\n<ul><li>Bir</li><li>Ikki</li></ul>\n\nYakun.";
    $blockFormatted = LegacyContentFormatter::paragraphize($withBlock);
    assert_contains('<p>Kirish matni.</p>', $blockFormatted);
    assert_contains('<ul><li>Bir</li><li>Ikki</li></ul>', $blockFormatted);
    assert_not_contains('<p><ul>', $blockFormatted);
    assert_contains('<p>Yakun.</p>', $blockFormatted);
});

test('News rendering: legacy formatter, balanced pagination and thumbnail fallback stay wired', function (): void {
    $social = (string) file_get_contents(APP_ROOT . '/app/Core/SocialEmbed.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/NewsController.php');
    $collector = (string) file_get_contents(APP_ROOT . '/app/Core/AssetCollector.php');
    $thumbJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/news-gallery-thumbnails.js');
    $pagerCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/news-index-pagination.css');

    assert_contains('LegacyContentFormatter::paragraphize($html)', $social, 'existing imported rows are formatted on render');

    assert_contains('$gridPageSize = \\App\\Core\\NewsFeedRhythm::PAGE_SIZE;', $controller, 'grid page size comes from the feed rhythm');
    // Особого размера у первой страницы больше нет: крупная карточка входит в
    // ритм ленты, а не стоит отдельной новостью над сеткой.
    assert_contains('$offset = ($page - 1) * $gridPageSize;', $controller, 'pages follow one another without a special first page');
    assert_not_contains('firstPageSize', $controller, 'no separate lead item to account for');
    assert_contains("requireCss('news-index-pagination'", $controller, 'news list loads pagination spacing');

    assert_contains("'news_gallery_thumbnails' => '/assets/js/news-gallery-thumbnails.js'", $collector, 'thumbnail helper is registered');
    assert_contains("source[type=\"image/webp\"]", $thumbJs, 'thumbnail helper prefers an existing responsive WebP source');
    assert_contains("gallery.querySelectorAll('.newsdetail-gallery__thumb img')", $thumbJs, 'thumbnail helper targets the visible strip');

    assert_contains('.newslist-grid + .listing-pager', $pagerCss, 'spacing is scoped to the news grid pager');
    assert_contains('margin-top: var(--space-xl, 3rem);', $pagerCss, 'desktop pager has breathing room');
});
