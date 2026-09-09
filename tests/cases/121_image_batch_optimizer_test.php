<?php

declare(strict_types=1);

use App\Core\ImageBatchOptimizer;
use App\Core\Media;

/** @return string */
function batch_optimizer_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/artstudio-webp-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    return $dir;
}

/** @param list<string> $files */
function batch_optimizer_cleanup(string $dir, array $files): void
{
    foreach ($files as $file) {
        @unlink($dir . '/' . $file);
    }
    @rmdir($dir);
}

test('ImageBatchOptimizer dry-run идемпотентен и не меняет оригинал', function (): void {
    $dir = batch_optimizer_temp_dir();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    file_put_contents($dir . '/legacy.png', $png);

    try {
        $first = ImageBatchOptimizer::run($dir, true);
        assert_same(1, $first['scanned']);
        assert_same(1, $first['planned']);
        assert_false(is_file($dir . '/legacy.webp'), 'dry-run не создаёт файлы');

        file_put_contents($dir . '/legacy.webp', 'existing');
        touch($dir . '/legacy.webp', time() + 2);
        $second = ImageBatchOptimizer::run($dir, true);
        assert_same(1, $second['skipped'], 'актуальный вариант пропущен');

        $forced = ImageBatchOptimizer::run($dir, true, true);
        assert_same(1, $forced['planned'], '--force планирует повторную генерацию');
    } finally {
        batch_optimizer_cleanup($dir, ['legacy.png', 'legacy.webp']);
    }
});

test('ImageBatchOptimizer создаёт full и responsive WebP', function (): void {
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        skip_test('GD/webp недоступны');
    }

    $dir = batch_optimizer_temp_dir();
    $image = imagecreatetruecolor(1000, 500);
    imagefilledrectangle($image, 0, 0, 1000, 500, imagecolorallocate($image, 30, 90, 150));
    imagejpeg($image, $dir . '/legacy.jpg', 85);
    $before = hash_file('sha256', $dir . '/legacy.jpg');

    try {
        $result = ImageBatchOptimizer::run($dir);
        assert_same(1, $result['optimized']);
        assert_true(is_file($dir . '/legacy.webp'));
        assert_true(is_file($dir . '/legacy-800.webp'));
        assert_same($before, hash_file('sha256', $dir . '/legacy.jpg'), 'оригинал не перезаписан');
    } finally {
        batch_optimizer_cleanup($dir, ['legacy.jpg', 'legacy.webp', 'legacy-800.webp']);
    }
});

test('Media добавляет нейтральный picture wrapper без навязанной высоты', function (): void {
    $name = 'media-size-' . bin2hex(random_bytes(4));
    $dir = APP_ROOT . '/public/uploads/public';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    file_put_contents($dir . '/' . $name . '.png', $png);
    file_put_contents($dir . '/' . $name . '.webp', 'test');

    try {
        $html = Media::picture('/uploads/public/' . $name . '.png', 'Тест');
        assert_contains('<picture class="media-picture">', $html);
        assert_true(!str_contains($html, 'width="1"'), 'Исходная ширина не должна менять размер CSS-компонента');
        assert_true(!str_contains($html, 'height="1"'), 'Исходная высота не должна растягивать карточку');
    } finally {
        @unlink($dir . '/' . $name . '.png');
        @unlink($dir . '/' . $name . '.webp');
    }
});
