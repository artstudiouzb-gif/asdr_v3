<?php

declare(strict_types=1);

use App\Core\PageHero;

/*
 * Обложка первым блоком заменяет шапку записи.
 *
 * Обложка несёт h1, вводный текст и собственную композицию во всю ширину.
 * Пока страница проекта печатала свой паспорт (метка «Проект», заголовок,
 * анонс и обложка-картинка) безусловно, на странице оказывалось два заголовка
 * подряд, две вводные части, а сама обложка уезжала вторым экраном — ровно то,
 * что видно на скриншоте владельца.
 *
 * Решение у страницы такое было всегда, поэтому правило вынесено в PageHero и
 * читается из одного места: вторая копия этих регулярок разъехалась бы с
 * первой при первой правке разметки блока.
 *
 * Отдельный случай — крошки. Обложек две: запись типа «Обложки»
 * (`<div class="hero …">`, HeroRenderer) и старая, собранная прямо в блоке
 * (`<div class="block-hero …">`). Поиск шёл только по второй, а на неудачу
 * крошки всё равно обнулялись — то есть на странице с обложкой-записью они
 * пропадали совсем.
 */

test('Первый блок-обложка опознаётся в обеих разметках', function () {
    $record = '<section class="cms-block cms-block--hero"><div class="hero hero--w-full"></div></section>';
    $legacy = '<section class="cms-block cms-block--hero"><div class="block-hero block-hero--plain"></div></section>';
    $text = '<section class="cms-block cms-block--text"><div></div></section>';

    foreach ([$record, $legacy] as $html) {
        assert_true(PageHero::isHero($html), 'обложка первым блоком');
        assert_true(PageHero::ownsHeading($html), 'обложка печатает h1 сама');
    }

    assert_false(PageHero::isHero($text), 'обычный блок шапкой не является');
    assert_false(PageHero::ownsHeading($text), 'обычный блок заголовка страницы не несёт');
});

test('Крошки встраиваются в обложку любого вида', function () {
    $crumbs = '<nav class="content-crumbs ' . PageHero::ON_HERO_CLASS . '"><span>Проект</span></nav>';

    foreach (['hero hero--w-full', 'block-hero block-hero--plain'] as $rootClass) {
        $html = '<section class="cms-block cms-block--hero"><div class="' . $rootClass . '">'
            . '<div class="hero__slides"></div></div></section>';
        [$content, $left] = PageHero::withCrumbs($html, $crumbs);

        assert_same('', $left, 'встроенные крошки вызывающему не возвращаются');
        assert_contains('cms-block--hero cms-block--page-hero', $content, 'секция помечена как шапка');
        // Внутри корня обложки, а не перед секцией: только там у них есть
        // позиционирующий предок.
        assert_true(
            strpos($content, $crumbs) > strpos($content, '<div class="' . $rootClass . '">'),
            'крошки лежат внутри обложки'
        );
        assert_true(strpos($content, $crumbs) < strpos($content, 'hero__slides'), 'крошки первыми в обложке');
    }
});

test('Неузнанная разметка обложки не теряет крошки', function () {
    $crumbs = '<nav class="content-crumbs ' . PageHero::ON_HERO_CLASS . '"><span>Проект</span></nav>';
    $html = '<section class="cms-block cms-block--hero"><article class="hero-next"></article></section>';

    [$content, $left] = PageHero::withCrumbs($html, $crumbs);

    assert_not_contains('content-crumbs', $content, 'встроить было некуда');
    assert_contains('<nav class="content-crumbs"', $left, 'крошки возвращены вызывающему');
    // Модификатор раскладывает навигацию absolute: вне обложки такая полоса
    // схлопнулась бы в ноль высоты.
    assert_not_contains(PageHero::ON_HERO_CLASS, $left, 'модификатор «поверх обложки» снят');
});

test('Страница и проект спрашивают PageHero, а не свои регулярки', function () {
    foreach (['page.php', 'project_show.php'] as $view) {
        $src = (string) file_get_contents(APP_ROOT . '/app/Views/site/' . $view);

        assert_contains('PageHero::isHero($content)', $src, $view . ': признак обложки — из PageHero');
        assert_contains('PageHero::ownsHeading($content)', $src, $view . ': признак заголовка — из PageHero');
        assert_contains('PageHero::withCrumbs($content, $crumbsHtml)', $src, $view . ': крошки — через PageHero');
        assert_false(
            (bool) preg_match('/preg_match\([^)]*cms-block--hero/', $src),
            $view . ': своей копии правила быть не должно'
        );
    }
});

test('Паспорт проекта не печатается под обложкой', function () {
    $src = (string) file_get_contents(APP_ROOT . '/app/Views/site/project_show.php');

    $guard = strpos($src, 'if (!$firstOwnsHeading)');
    assert_true($guard !== false, 'паспорт записи под условием');
    foreach (['projdetail-head', 'projdetail__title', 'projdetail__lead', 'projdetail__media'] as $part) {
        assert_true(strpos($src, $part) > $guard, $part . ' — внутри условия');
    }
});

test('Крошки поверх обложки-записи разложены CSS', function () {
    $css = \theme_css();

    // Без правила для .hero навигация осталась бы в потоке поверх кадра:
    // у старой обложки класс другой (.block-hero), и одного его мало.
    assert_contains('.hero .content-crumbs--on-hero', $css, 'обложка-запись знает про крошки');
    // Обложка-шапка на странице проекта лежит не прямым ребёнком .site-content,
    // поэтому отступ снимается по потомку, а не по прямому ребёнку.
    assert_contains('.site-content:has(.cms-block--page-hero)', $css, 'отступ снят и у вложенной обложки');
    assert_contains('.projdetail--hero', $css, 'у страницы проекта свой верхний отступ');
});
