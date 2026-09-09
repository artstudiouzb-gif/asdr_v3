<?php

declare(strict_types=1);

use App\Core\Uploader;

// Оптимизация изображений: даунскейл слишком большого оригинала + webp-варианты.

test('optimizeImage уменьшает слишком широкий оригинал и создаёт webp', function () {
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        skip_test('GD/webp недоступны');
    }
    $dir = sys_get_temp_dir() . '/imgopt_' . bin2hex(random_bytes(3));
    @mkdir($dir);
    $path = $dir . '/big.jpg';
    $img = imagecreatetruecolor(3200, 1800);
    imagefilledrectangle($img, 0, 0, 3200, 1800, imagecolorallocate($img, 30, 80, 140));
    imagejpeg($img, $path, 90);

    Uploader::optimizeImage($path);

    [$w] = getimagesize($path);
    assert_true($w <= Uploader::originalMaxWidth(), "оригинал уменьшен до <= максимума (стал {$w}px)");
    assert_true(is_file($dir . '/big.webp'), 'создан webp полного размера');
    assert_true(is_file($dir . '/big-800.webp'), 'создан webp 800px');

    // Уборка.
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
});

test('originalMaxWidth в разумных пределах', function () {
    $w = Uploader::originalMaxWidth();
    assert_true($w >= 1200 && $w <= 4000, 'ширина ограничена диапазоном 1200..4000');
});
