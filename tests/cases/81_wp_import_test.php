<?php

declare(strict_types=1);

use App\Core\LegacyCmsImporter;

test('WP import: приватный/loopback источник блокируется до сетевого запроса', function () {
    $blocked = false;
    try {
        LegacyCmsImporter::importAll('http://127.0.0.1');
    } catch (InvalidArgumentException) {
        $blocked = true;
    }
    assert_true($blocked, 'SSRF-адрес отклонён');
});

// Импорт из старой CMS: чистые преобразования (без сети/БД).

test('mapPost извлекает поля поста WP REST', function () {
    $post = [
        'slug' => 'moya-novost',
        'link' => 'https://old.example/2025/03/moya-novost/',
        'date' => '2025-03-14T10:30:00',
        'title' => ['rendered' => 'Заголовок &amp; тест'],
        'excerpt' => ['rendered' => '<p>Краткое   описание.</p>'],
        'content' => ['rendered' => '<p>Тело <img src="https://old.example/a.jpg"></p>'],
        '_embedded' => ['wp:featuredmedia' => [['source_url' => 'https://old.example/cover.jpg']]],
    ];
    $m = LegacyCmsImporter::mapPost($post);
    assert_same('Заголовок & тест', $m['title'], 'HTML-сущности раскодированы, теги убраны');
    assert_same('moya-novost', $m['slug'], 'slug из поста');
    assert_same('Краткое описание.', $m['excerpt'], 'анонс очищен от тегов и лишних пробелов');
    assert_same('2025-03-14 10:30:00', $m['published_at'], 'дата приведена к формату БД');
    assert_same('https://old.example/cover.jpg', $m['featured_url'], 'обложка из featured media');
});

test('extractImageUrls находит все картинки, rewriteImages заменяет по карте', function () {
    $html = '<p><img src="https://o/a.jpg" alt="x"> текст <img src=\'https://o/b.png\'></p>';
    $urls = LegacyCmsImporter::extractImageUrls($html);
    assert_same(2, count($urls), 'найдены обе картинки');
    assert_true(in_array('https://o/a.jpg', $urls, true), 'первая');

    $rewritten = LegacyCmsImporter::rewriteImages($html, [
        'https://o/a.jpg' => '/uploads/public/a.jpg',
        'https://o/b.png' => '/uploads/public/b.png',
    ]);
    assert_true(str_contains($rewritten, '/uploads/public/a.jpg'), 'первая переписана');
    assert_true(!str_contains($rewritten, 'https://o/b.png'), 'вторая заменена');
});

test('normalizeImageUrl снимает Jetpack Photon и query, возвращая оригинал', function () {
    $src = 'https://i0.wp.com/asdr.gov.uz/wp-content/uploads/2026/07/2.jpg?resize=351%2C234&#038;ssl=1';
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/2.jpg', LegacyCmsImporter::normalizeImageUrl($src), 'Photon-обёртка и query сняты');
    $clean = 'https://asdr.gov.uz/wp-content/uploads/2026/07/1-scaled.jpg';
    assert_same($clean, LegacyCmsImporter::normalizeImageUrl($clean), 'чистый URL не изменяется');
});

test('stripResponsiveAttrs убирает srcset/sizes', function () {
    $html = '<img src="/a.jpg" srcset="a 300w, b 1024w" sizes="(max-width: 351px) 100vw, 351px">';
    $out = LegacyCmsImporter::stripResponsiveAttrs($html);
    assert_true(!str_contains($out, 'srcset') && !str_contains($out, 'sizes'), 'srcset и sizes удалены');
    assert_true(str_contains($out, 'src="/a.jpg"'), 'основной src сохранён');
});

test('absoluteUrl абсолютизирует относительные и protocol-relative ссылки', function () {
    $base = 'https://old.example';
    assert_same('https://old.example/x/y.jpg', LegacyCmsImporter::absoluteUrl('/x/y.jpg', $base), 'относительная от корня');
    assert_same('https://cdn/z.jpg', LegacyCmsImporter::absoluteUrl('//cdn/z.jpg', $base), 'protocol-relative');
    assert_same('https://o/w.jpg', LegacyCmsImporter::absoluteUrl('https://o/w.jpg', $base), 'уже абсолютная');
});

test('imageUrlCandidates возвращает полноразмерный оригинал и варианты разрешения', function () {
    $thumb = 'https://asdr.gov.uz/wp-content/uploads/2026/07/photo-300x200.jpg';
    $candidates = LegacyCmsImporter::imageUrlCandidates($thumb);
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/photo.jpg', $candidates[0], 'первым идёт полноразмерный оригинал');
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/photo-scaled.jpg', $candidates[1], 'вторым идёт scaled-вариант');
    assert_same($thumb, $candidates[2], 'третьим идёт исходный thumbnail как fallback');

    $pngThumb = 'https://asdr.gov.uz/wp-content/uploads/2026/07/screen-1024x768.png';
    $pngCandidates = LegacyCmsImporter::imageUrlCandidates($pngThumb);
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/screen.png', $pngCandidates[0], 'png полноразмерный');

    $scaled = 'https://asdr.gov.uz/wp-content/uploads/2026/07/banner-scaled.jpg';
    $scaledCandidates = LegacyCmsImporter::imageUrlCandidates($scaled);
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/banner.jpg', $scaledCandidates[0], 'scaled разрешается к оригиналу');
    assert_same($scaled, $scaledCandidates[1], 'scaled сохранён как fallback');
});

test('LegacyWxrImporter extractGalleryIds распознаёт шорткоды, Gutenberg блоки и html-атрибуты', function () {
    $html = <<<HTML
    <p>Введение</p>
    [gallery ids="101, 102, 103"]
    <!-- wp:gallery {"ids":[201,202],"linkTo":"media"} -->
    <figure class="wp-block-gallery has-nested-images columns-default is-cropped">
        <!-- wp:image {"id":201,"sizeSlug":"large"} -->
        <figure class="wp-block-image size-large"><img src="https://old/img1-1024x768.jpg" alt="" class="wp-image-201"/></figure>
        <!-- /wp:image -->
        <!-- wp:image {"id":202,"sizeSlug":"large"} -->
        <figure class="wp-block-image size-large"><img src="https://old/img2-1024x768.jpg" alt="" class="wp-image-202" data-id="202"/></figure>
        <!-- /wp:image -->
    </figure>
    <!-- /wp:gallery -->
    <p><img class="alignnone size-medium wp-image-301" src="https://old/single-300x200.jpg" alt="" width="300" height="200" data-id="302" /></p>
HTML;

    $ids = \App\Core\LegacyWxrImporter::extractGalleryIds($html);
    assert_true(in_array(101, $ids, true), 'шорткод 101');
    assert_true(in_array(102, $ids, true), 'шорткод 102');
    assert_true(in_array(103, $ids, true), 'шорткод 103');
    assert_true(in_array(201, $ids, true), 'Gutenberg gallery 201');
    assert_true(in_array(202, $ids, true), 'Gutenberg gallery 202');
    assert_true(in_array(301, $ids, true), 'wp-image класс 301');
    assert_true(in_array(302, $ids, true), 'data-id 302');
});
