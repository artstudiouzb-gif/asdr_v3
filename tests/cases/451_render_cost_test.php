<?php

declare(strict_types=1);

use App\Core\Media;
use App\Core\View;

/*
 * Производительность отрисовки (аудит ноября 2026): признаки страницы
 * печатает сервер, а не выводит браузер через :has() на корне документа.
 */

test('В публичном CSS нет :has() на корне документа', function (): void {
    // `body:has(.x)` помечает корень зависимым от всего дерева: любая смена
    // текста или класса пересчитывала стили всех ~1000 элементов страницы —
    // замерено 7–9 мс против 0,3 мс на изменение, −500 мс блокировки
    // главного потока на мобильной главной. Признак ставит сервер классом.
    $offenders = [];
    foreach (array_merge(
        glob(APP_ROOT . '/public/assets/css/*.css') ?: [],
        glob(APP_ROOT . '/public/assets/css/blocks/*.css') ?: []
    ) as $file) {
        if (str_contains($file, '.min.') || str_contains(basename($file), 'admin')) {
            continue;
        }
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
        if (preg_match_all('/(?<![\w-])(html|body|:root|\.site-content)(?:[.#\[][^\s,{>+~]*)?:has\(/', $css, $m) > 0) {
            $offenders[basename($file)] = count($m[0]);
        }
    }
    assert_same([], $offenders, 'признак шапки или содержимого — классом с сервера');
});

test('Признаки содержимого ставятся классом на <main class="site-content">', function (): void {
    $page = '<body><main class="site-content" id="main-content"><section class="cms-block cms-block--hero cms-block--page-hero"></section></main></body>';
    assert_contains('<main class="site-content has-page-hero"', View::markSiteContent($page));

    $news = '<main class="site-content" id="main-content"><article class="newsdetail newsdetail--premium"></article></main>';
    assert_contains('<main class="site-content has-newsdetail-premium"', View::markSiteContent($news));

    $plain = '<main class="site-content" id="main-content"><p>Текст</p></main>';
    assert_same($plain, View::markSiteContent($plain), 'без признаков разметка не меняется');
});

test('Preload кадра умеет медиазапрос — у телефона свой кадр', function (): void {
    $desktop = Media::preloadLink('/uploads/public/a.jpg', '100vw', '(min-width: 721px)');
    $mobile = Media::preloadLink('/uploads/public/b.jpg', '100vw', '(max-width: 720px)');
    assert_contains('media="(min-width: 721px)"', $desktop);
    assert_contains('media="(max-width: 720px)"', $mobile);
    assert_not_contains('media=', Media::preloadLink('/uploads/public/a.jpg'));
});
