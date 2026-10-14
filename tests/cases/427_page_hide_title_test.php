<?php

declare(strict_types=1);

use App\Core\View;

// «Скрыть заголовок страницы» убирает шапку страницы с экрана, не трогая
// прозрачность шапки сайта, а h1 оставляет визуально скрытым: без него у
// страницы не было бы главного заголовка ни для диктора, ни для поиска.
test('Страница: «Скрыть заголовок» убирает шапку страницы, h1 остаётся скрытым (БД)', function (): void {
    ensure_test_db();
    $_SESSION = $_SESSION ?? [];
    $render = static fn (array $extra): string => View::renderPartial('site/page', [
        'page' => ['id' => 0, 'title' => 'Стратегия 2030', 'meta_title' => '', 'meta_description' => '',
            'lead' => 'Вводный текст страницы', 'layout_type' => 'no_sidebar'] + $extra,
        'content' => '<section class="cms-block"><p>Тело страницы</p></section>',
        'blockCss' => '',
        'layoutType' => 'no_sidebar',
        'sidebar' => null,
    ]);

    $shown = $render([]);
    assert_contains('content-pagehead__title', $shown);
    assert_contains('editorial-page__crumbs', $shown);
    assert_contains('<p class="content-pagehead__lead">Вводный текст страницы', $shown);

    // Вводный текст остаётся в <meta name="description"> — скрыта только
    // шапка страницы над содержимым.
    $hidden = $render(['hide_title' => 1]);
    assert_not_contains('content-pagehead__title', $hidden);
    assert_not_contains('content-pagehead__lead', $hidden);
    // Крошки — свой флажок: скрытый заголовок их не трогает.
    assert_contains('editorial-page__crumbs', $hidden);

    $noCrumbs = $render(['hide_title' => 1, 'hide_crumbs' => 1]);
    assert_not_contains('editorial-page__crumbs', $noCrumbs);
    assert_contains('<h1 class="visually-hidden">Стратегия 2030</h1>', $noCrumbs);
    assert_contains('<h1 class="visually-hidden">Стратегия 2030</h1>', $hidden);
    assert_contains('Тело страницы', $hidden);
});

// Проект — строка той же таблицы, и флажки у него те же: паспорт скрывается,
// h1 остаётся визуально скрытым, крошки уходят своим флажком.
test('Проект: «Скрыть заголовок» и «Скрыть хлебные крошки» (БД)', function (): void {
    ensure_test_db();
    $_SESSION = $_SESSION ?? [];
    $render = static fn (array $extra): string => View::renderPartial('site/project_show', [
        'project' => ['id' => 0, 'title' => 'Davlat boshqaruvi', 'slug' => 'davlat', 'description' => 'Anons matni',
            'cover_image' => '', 'lang' => 'uz'] + $extra,
        'content' => '<section class="cms-block"><p>Loyiha matni</p></section>',
        'blockCss' => '',
    ]);

    $shown = $render([]);
    assert_contains('projdetail__title', $shown);
    assert_contains('content-crumbs', $shown);

    $hidden = $render(['hide_title' => 1]);
    assert_not_contains('projdetail__title', $hidden);
    assert_not_contains('projdetail__lead', $hidden);
    assert_contains('<h1 class="visually-hidden">Davlat boshqaruvi</h1>', $hidden);
    assert_contains('content-crumbs', $hidden);

    assert_not_contains('content-crumbs', $render(['hide_title' => 1, 'hide_crumbs' => 1]));
});
