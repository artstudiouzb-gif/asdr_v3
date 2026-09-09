<?php

declare(strict_types=1);

use App\Models\Page;

test('Page::findHome и Page::isHomePage точно определяют главную страницу для каждого языка', function (): void {
    ensure_test_db();

    $ruHomeId = Page::create([
        'title' => 'Главная страница (RU)',
        'slug' => 'home-ru-' . bin2hex(random_bytes(2)),
        'status' => 'published',
        'is_home' => 1,
        'lang' => 'ru',
    ]);

    $uzHomeId = Page::create([
        'title' => 'Bosh sahifa (UZ)',
        'slug' => 'home-uz-' . bin2hex(random_bytes(2)),
        'status' => 'published',
        'is_home' => 1,
        'lang' => 'uz',
        'translation_group_id' => $ruHomeId,
    ]);

    $ruHome = Page::findHome('ru');
    $uzHome = Page::findHome('uz');

    assert_equals($ruHomeId, (int) $ruHome['id'], 'RU findHome возвращает именно русскую главную страницу');
    assert_equals($uzHomeId, (int) $uzHome['id'], 'UZ findHome возвращает именно узбекскую главную страницу');

    assert_true(Page::isHomePage($ruHomeId, 'ru'), 'RU страница признаётся главной для RU');
    assert_true(Page::isHomePage($uzHomeId, 'uz'), 'UZ страница признаётся главной для UZ');

    assert_false(Page::isHomePage([]), 'Пустой массив новой страницы не вызывает ошибку undefined key "id"');
    assert_false(Page::isHomePage(['title' => 'Новая страница']), 'Новая несохранённая страница признаётся не главной');
});
