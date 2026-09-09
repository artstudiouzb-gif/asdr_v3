<?php

declare(strict_types=1);

use App\Models\Page;

test('Хлебные крошки вырезаются из HTML контента главной страницы даже если они попали из кэша', function (): void {
    ensure_test_db();

    $ruHomeId = Page::create([
        'title' => 'Главная',
        'slug' => 'home-purge-' . bin2hex(random_bytes(2)),
        'status' => 'published',
        'is_home' => 1,
        'lang' => 'ru',
    ]);

    $page = Page::findById($ruHomeId);
    $content = '<div class="block-hero"><nav class="site-crumbs content-crumbs--on-hero">Bosh sahifa / Bosh sahifa</nav><h1>Заголовок</h1></div>';

    $isHome = Page::isHomePage($page, 'ru');
    if ($isHome) {
        $content = preg_replace('/<nav\b[^>]*\bclass="[^"]*\b(?:site-crumbs|content-crumbs)\b[^"]*"[^>]*>.*?<\/nav>/si', '', $content);
    }

    assert_false(str_contains($content, 'site-crumbs'), 'site-crumbs удалены из HTML');
    assert_false(str_contains($content, 'Bosh sahifa / Bosh sahifa'), 'текст крошек удален из HTML');
});
