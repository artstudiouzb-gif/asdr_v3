<?php

declare(strict_types=1);

use App\Core\Pager;

// Полоса страниц: окно номеров вокруг текущей вместо стены цифр и ссылки
// prev/next для поисковика.

test('Pager: окно номеров с разрывами', function () {
    assert_same([1], Pager::items(1, 1));
    assert_same([1, 2, 3], Pager::items(1, 3), 'три страницы помещаются целиком');

    // Классический вид: края, окно вокруг текущей, многоточия на разрывах.
    assert_same([1, null, 4, 5, 6, null, 40], Pager::items(5, 40));
    assert_same([1, 2, 3, null, 40], Pager::items(2, 40), 'у начала левого разрыва нет');
    assert_same([1, null, 38, 39, 40], Pager::items(39, 40));

    // Разрыв в одну страницу многоточием не прячем: места столько же, а
    // читателю пришлось бы делать лишний клик.
    assert_same([1, 2, 3, 4, 5], Pager::items(3, 5));
    assert_same([1, 2, 3, 4, null, 9], Pager::items(3, 9));

    // Номер вне диапазона не ломает полосу.
    assert_same([1, 2, 3], Pager::items(99, 3));
    assert_same([1, 2, 3], Pager::items(0, 3));
});

test('Pager: ссылки на соседние страницы', function () {
    $url = static fn (int $p): string => '/news?page=' . $p;

    assert_same(['prev' => '', 'next' => '/news?page=2'], Pager::relLinks(1, 5, $url));
    assert_same(['prev' => '/news?page=2', 'next' => '/news?page=4'], Pager::relLinks(3, 5, $url));
    assert_same(['prev' => '/news?page=4', 'next' => ''], Pager::relLinks(5, 5, $url));
    assert_same(['prev' => '', 'next' => ''], Pager::relLinks(1, 1, $url));
});

test('Полоса страниц одна на ленту и каталог, стрелки подписаны для диктора', function () {
    $pager = (string) file_get_contents(APP_ROOT . '/app/Views/site/_pager.php');
    assert_contains('Pager::items', $pager);
    assert_contains('rel="prev"', $pager);
    assert_contains('rel="next"', $pager);
    // У стрелки только знак — подпись нужна экранному диктору.
    assert_contains('visually-hidden', $pager);
    assert_contains('aria-current="page"', $pager);

    // Обе вьюхи используют общий партиал: разойдясь, они дали бы разное
    // поведение на половинах сайта.
    foreach (['_news_list.php', '_catalog_list.php'] as $view) {
        $markup = (string) file_get_contents(APP_ROOT . '/app/Views/site/' . $view);
        assert_contains("require __DIR__ . '/_pager.php'", $markup, $view);
        assert_not_contains('for ($i = 1; $i <= $pages', $markup, $view . ': своя копия полосы страниц');
    }

    // Шапка выводит rel-ссылки, если вью их посчитала.
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    assert_contains('$pagerPrev', $header);
    assert_contains('$pagerNext', $header);
});
