<?php

declare(strict_types=1);

use App\Core\LegacyCmsImporter;
use App\Core\Media;

/*
 * Разрешение формата изображений при импорте новостей из старой CMS:
 * если на старом сайте или в медиатеке снимок существует как WebP (или стал
 * WebP после оптимизации), система не должна ломаться при поиске .jpg.
 */

test('imageUrlCandidates включает WebP-варианты как fallback для растровых изображений', function () {
    $thumb = 'https://asdr.gov.uz/wp-content/uploads/2026/07/photo-300x200.jpg';
    $candidates = LegacyCmsImporter::imageUrlCandidates($thumb);

    // Первые 3 кандидата сохраняют обратную совместимость (тест 81)
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/photo.jpg', $candidates[0]);
    assert_same('https://asdr.gov.uz/wp-content/uploads/2026/07/photo-scaled.jpg', $candidates[1]);
    assert_same($thumb, $candidates[2]);

    // В кандидатах обязаны присутствовать WebP-варианты на случай, если старый WP
    // удалил оригинальный JPG или хранит только WebP
    assert_true(
        in_array('https://asdr.gov.uz/wp-content/uploads/2026/07/photo.webp', $candidates, true),
        'полноразмерный webp включён в кандидаты'
    );
    assert_true(
        in_array('https://asdr.gov.uz/wp-content/uploads/2026/07/photo-scaled.webp', $candidates, true),
        'scaled webp включён в кандидаты'
    );
});

test('Media::resolveExistingMediaUrl разрешает отсутствующий JPG к существующему WebP', function () {
    $uploadsDir = APP_ROOT . '/public/uploads/public';
    $testJpg = 'test-fallback-' . bin2hex(random_bytes(4)) . '.jpg';
    $testWebp = preg_replace('/\.jpg$/', '.webp', $testJpg);
    $webpDiskPath = $uploadsDir . '/' . $testWebp;

    // Создаём только WebP файл на диске, JPG не существует
    file_put_contents($webpDiskPath, 'dummy-webp-content');
    chmod($webpDiskPath, 0644);

    try {
        $resolved = Media::resolveExistingMediaUrl('/uploads/public/' . $testJpg);
        assert_same(
            '/uploads/public/' . $testWebp,
            $resolved,
            'отсутствующий JPG разрешился к существующему WebP'
        );

        // Когда JPG реально есть на диске — URL остаётся без изменений
        $jpgDiskPath = $uploadsDir . '/' . $testJpg;
        file_put_contents($jpgDiskPath, 'dummy-jpg-content');
        chmod($jpgDiskPath, 0644);

        $resolvedExisting = Media::resolveExistingMediaUrl('/uploads/public/' . $testJpg);
        assert_same(
            '/uploads/public/' . $testJpg,
            $resolvedExisting,
            'существующий JPG не подменяется'
        );
        @unlink($jpgDiskPath);
    } finally {
        @unlink($webpDiskPath);
    }
});

test('Media::picture использует существующий WebP вместо 404 по отсутствующему JPG', function () {
    $uploadsDir = APP_ROOT . '/public/uploads/public';
    $baseName = 'test-pic-fallback-' . bin2hex(random_bytes(4));
    $webpDiskPath = $uploadsDir . '/' . $baseName . '.webp';

    file_put_contents($webpDiskPath, 'fake-webp');
    chmod($webpDiskPath, 0644);

    try {
        $html = Media::picture('/uploads/public/' . $baseName . '.jpg', 'Тестовая новость');
        // В теге img src не должно остаться отсутствующего JPG
        assert_true(
            str_contains($html, '/uploads/public/' . $baseName . '.webp'),
            'картинка ссылается на существующий WebP'
        );
        assert_true(
            !str_contains($html, 'src="/uploads/public/' . $baseName . '.jpg"'),
            'в src нет отсутствующего JPG'
        );
    } finally {
        @unlink($webpDiskPath);
    }
});

test('Web-сервер и точка входа имеют fallback для WebP при запросе отсутствующего JPG', function () {
    $htaccess = (string) file_get_contents(APP_ROOT . '/public/.htaccess');
    $index = (string) file_get_contents(APP_ROOT . '/public/index.php');

    assert_contains('RewriteCond %{REQUEST_FILENAME} !-f', $htaccess);
    assert_contains('RewriteCond %1.webp -f', $htaccess);
    assert_contains('RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,L]', $htaccess);

    assert_contains("str_starts_with(\$requestPath, '/uploads/public/')", $index);
    assert_contains("header('Content-Type: image/webp')", $index);
});
