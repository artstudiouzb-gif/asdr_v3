<?php

declare(strict_types=1);

test('служебная подпись Поделиться не входит в иерархию заголовков', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    assert_true(!str_contains($view, '<h2 class="newsdetail-share__title">'));
    assert_contains('<p class="newsdetail-share__title">', $view);
});

test('заголовки sidebar-виджетов не занимают уровень разделов страницы', function (): void {
    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/WidgetRenderer.php');
    $newsView = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    assert_contains('<h3 class="widget__title">', $renderer);
    assert_true(!str_contains($renderer, '<h2 class="widget__title">'));

    assert_contains('<h3 class="newsdetail-card__title">', $newsView);
    assert_true(!str_contains($newsView, '<h2 class="newsdetail-card__title">'));
    assert_contains('<h3 class="newsdetail-subscribe__title">', $newsView);
    assert_true(!str_contains($newsView, '<h2 class="newsdetail-subscribe__title">'));
});
