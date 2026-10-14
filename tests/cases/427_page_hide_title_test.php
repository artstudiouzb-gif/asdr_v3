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
