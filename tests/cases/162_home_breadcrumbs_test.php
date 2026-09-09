<?php

declare(strict_types=1);

use App\Models\Page;

test('Хлебные крошки не выводятся для главной страницы любого языка', function (): void {
    ensure_test_db();

    $ruHomeId = Page::create([
        'title' => 'Главная страница',
        'slug' => 'home-bc-ru-' . bin2hex(random_bytes(2)),
        'status' => 'published',
        'is_home' => 1,
        'lang' => 'ru',
    ]);

    $uzHomeId = Page::create([
        'title' => 'Bosh sahifa',
        'slug' => 'home-bc-uz-' . bin2hex(random_bytes(2)),
        'status' => 'published',
        'is_home' => 1,
        'lang' => 'uz',
        'translation_group_id' => $ruHomeId,
    ]);

    $ruHome = Page::findById($ruHomeId);
    $uzHome = Page::findById($uzHomeId);

    assert_true(Page::isHomePage($ruHome, 'ru'), 'RU распознается как главная');
    assert_true(Page::isHomePage($uzHome, 'uz'), 'UZ распознается как главная');
});
