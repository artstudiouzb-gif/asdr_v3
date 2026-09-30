<?php

declare(strict_types=1);

use App\Core\Config;

/*
 * Мелкое превью берёт уменьшенную копию, а не оригинал. Виджет «Последние
 * новости» и подсказки поиска печатали адрес обложки как есть: кадр 68×48
 * качал оригинал в сотни килобайт и ужимал его стилями.
 */

/**
 * Файл загрузки с копией -400.webp рядом; возвращает адрес оригинала и
 * функцию уборки.
 *
 * @return array{0: string, 1: callable(): void}
 */
function thumb_probe_upload(bool $withVariant): array
{
    $dir = rtrim((string) Config::get('paths.public_uploads', ''), '/');
    $url = rtrim((string) Config::get('paths.public_uploads_url', '/uploads/public'), '/');
    if ($dir === '' || !is_dir($dir)) {
        skip_test('каталог загрузок не настроен');
    }
    $name = 'thumb-probe-' . bin2hex(random_bytes(4));
    $files = [$dir . '/' . $name . '.jpg'];
    if ($withVariant) {
        $files[] = $dir . '/' . $name . '-400.webp';
    }
    foreach ($files as $file) {
        file_put_contents($file, 'x');
        chmod($file, 0644);
    }

    return [$url . '/' . $name . '.jpg', static function () use ($files): void {
        foreach ($files as $file) {
            @unlink($file);
        }
    }];
}

test('Виджет последних новостей показывает уменьшенную копию обложки', function (): void {
    [$cover, $cleanup] = thumb_probe_upload(true);
    [$bare, $cleanupBare] = thumb_probe_upload(false);
    try {
        $rows = sample_news_rows(3);
        $rows[0]['image'] = $cover;
        $rows[1]['image'] = $bare;
        $rows[2]['image'] = '';
        $rows[2]['video_url'] = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $html = render_view('templates/widgets/latest_news.php', [
            'data' => ['items' => $rows, 'show_thumb' => 1], 'lang' => 'ru',
        ]);
        $variant = preg_replace('/\.jpg$/', '-400.webp', $cover);
        assert_contains('src="' . $variant . '"', $html, 'есть копия — берётся она');
        assert_not_contains('src="' . $cover . '"', $html, 'оригинал в кадр 68×48 не идёт');
        assert_contains('src="' . $bare . '"', $html, 'копий нет — остаётся оригинал, а не пустой кадр');
        assert_contains('i.ytimg.com', $html, 'превью ролика остаётся чужим адресом');
    } finally {
        $cleanup();
        $cleanupBare();
    }
});

test('Подсказки поиска показывают уменьшенную копию снимка', function (): void {
    [$image, $cleanup] = thumb_probe_upload(true);
    try {
        $html = render_view('app/Views/site/_search_suggest.php', [
            'results' => [['type' => 'Новости', 'title' => 'Проба', 'url' => '/news/probe', 'excerpt' => '', 'image' => $image]],
            'query' => 'проба', 'allUrl' => '/search?q=проба',
        ]);
        assert_contains('src="' . preg_replace('/\.jpg$/', '-400.webp', $image) . '"', $html);
        assert_not_contains('src="' . $image . '"', $html);
    } finally {
        $cleanup();
    }
});
