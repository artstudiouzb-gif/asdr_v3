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
    assert_contains('Вводный текст страницы', $shown);

    $hidden = $render(['hide_title' => 1]);
    assert_not_contains('content-pagehead', $hidden);
    assert_not_contains('Вводный текст страницы', $hidden);
    assert_contains('<h1 class="visually-hidden">Стратегия 2030</h1>', $hidden);
    assert_contains('Тело страницы', $hidden);
});
