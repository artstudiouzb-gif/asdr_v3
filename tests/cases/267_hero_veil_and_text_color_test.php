<?php

declare(strict_types=1);

use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;

/*
 * Наложение поверх фона и цвет текста обложки. Оба места решали за редактора и
 * ошибались молча: любое наложение считалось затемнением (даже белое, которое
 * кадр осветляет), а выбранный вручную цвет текста затирался автоподбором.
 */

/** @param array<string, mixed> $slide */
function hero_veil_render(array $slide, array $settings = []): string
{
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Проба'],
        [hero_test_slide($slide, 1)],
        HeroSettings::withDefaults($settings),
        11
    );

    return $rendered['html'] . ($rendered['css'] ?? '');
}

test('Светлое наложение осветляет кадр, и текст становится тёмным', function () {
    $photo = ['title' => 'Проба', 'media_type' => 'image', 'image' => '/uploads/public/a.jpg'];

    // Тёмная вуаль — как было: текст светлый.
    $dark = hero_veil_render($photo + [
        'overlay' => 'solid', 'overlay_color' => '#0b1a30', 'overlay_opacity' => 60,
    ]);
    assert_contains('hero--content-light', $dark, 'под тёмной вуалью текст обязан быть светлым');

    // Белая вуаль той же плотности осветляет кадр — значит текст тёмный.
    $light = hero_veil_render($photo + [
        'overlay' => 'solid', 'overlay_color' => '#ffffff', 'overlay_opacity' => 60,
    ]);
    assert_contains('hero--content-dark', $light, 'под светлой вуалью текст остался светлым — он не читается');

    // Слабое наложение сквозь себя показывает фотографию, и решать по нему
    // нельзя: цвет берётся по схеме, как и раньше.
    $weak = hero_veil_render($photo + [
        'overlay' => 'solid', 'overlay_color' => '#ffffff', 'overlay_opacity' => 10,
    ]);
    assert_contains('hero--content-light', $weak, 'слабая вуаль не должна решать за фотографию');
});

test('Прозрачная шапка идёт за наложением, а не только за схемой', function () {
    $photo = ['title' => 'Проба', 'media_type' => 'image', 'image' => '/uploads/public/a.jpg'];

    $light = hero_veil_render($photo + [
        'overlay' => 'solid', 'overlay_color' => '#ffffff', 'overlay_opacity' => 60,
    ]);
    assert_contains('data-hero-scheme="light"', $light, 'на осветлённом кадре шапка обязана перейти в тёмный набор');

    $dark = hero_veil_render($photo + [
        'overlay' => 'solid', 'overlay_color' => '#0b1a30', 'overlay_opacity' => 60,
    ]);
    assert_contains('data-hero-scheme="dark"', $dark, 'под тёмной вуалью шапка остаётся светлой');

    // Без наложения яркость фотографии никому не известна — шапка держится на
    // своей подложке и остаётся светлой.
    $bare = hero_veil_render($photo + ['overlay' => 'none']);
    assert_contains('data-hero-scheme="dark"', $bare, 'без наложения шапка не должна доверять схеме');
});

test('Наложение решает и поверх заливки, а не только поверх фотографии', function () {
    // Светлая вуаль осветляет navy-заливку ровно так же, как снимок. Пока
    // проверка стояла только в ветке «есть медиа», такой кадр оставался с
    // белым текстом на осветлённом фоне.
    $onFill = hero_veil_render([
        'title' => 'Проба', 'media_type' => 'none',
        'overlay' => 'solid', 'overlay_color' => '#ffffff', 'overlay_opacity' => 70,
    ], ['scheme' => 'navy', 'content_scheme' => 'auto']);
    assert_contains('hero--content-dark', $onFill, 'светлая вуаль поверх заливки оставила светлый текст');
    assert_contains('data-hero-scheme="light"', $onFill, 'шапка не заметила осветлённую заливку');

    // Зеркальный случай: тёмная вуаль поверх светлой заливки.
    $darkOnLight = hero_veil_render([
        'title' => 'Проба', 'media_type' => 'none',
        'overlay' => 'solid', 'overlay_color' => '#0b1a30', 'overlay_opacity' => 70,
    ], ['scheme' => 'light', 'content_scheme' => 'auto']);
    assert_contains('hero--content-light', $darkOnLight, 'тёмная вуаль поверх светлой заливки не затемнила кадр');
    assert_contains('data-hero-scheme="dark"', $darkOnLight, 'шапка не заметила затемнённую заливку');
});

test('Градиентное наложение решает за текст, но не за шапку', function () {
    // Градиент гуще там, где стоит текст, и к другому краю сходит на нет.
    // Шапка тянется во всю ширину: над прозрачным краем тёмный набор лёг бы
    // поверх неосветлённого снимка. Там работает её собственная подложка.
    $gradient = hero_veil_render([
        'title' => 'Проба', 'media_type' => 'image', 'image' => '/uploads/public/a.jpg',
        'overlay' => 'gradient', 'overlay_color' => '#ffffff', 'overlay_opacity' => 70,
    ], ['content_scheme' => 'auto']);

    assert_contains('hero--content-dark', $gradient, 'под светлым градиентом текст обязан быть тёмным');
    assert_contains('data-hero-scheme="dark"', $gradient, 'шапка доверилась градиенту, которого у её края нет');
});

test('Выбранный вручную цвет текста не затирается автоподбором', function () {
    // Классы hero--content-light/dark объявляют --hero-fg на .hero__text, то
    // есть глубже схемы. Пока они висят на слайде, свой цвет не виден.
    $custom = hero_veil_render(
        ['title' => 'Проба'],
        ['scheme' => 'custom', 'scheme_bg' => '#123a5e', 'scheme_text' => '#ffd166']
    );
    assert_contains('hero--content-custom', $custom, 'свой цвет текста снова перебивается автоподбором');
    assert_not_contains('hero--content-light', $custom, 'на слайде остался класс автоподбора');
    assert_contains('#ffd166', $custom, 'выбранный цвет не доехал до стилей');

    // Явный выбор «светлый/тёмный» остаётся за редактором и главнее custom.
    $explicit = hero_veil_render(
        ['title' => 'Проба', 'content_scheme' => 'light'],
        ['scheme' => 'custom', 'scheme_text' => '#ffd166']
    );
    assert_contains('hero--content-light', $explicit, 'явный выбор цвета текста перестал действовать');

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    assert_not_contains('.hero--content-custom', $css, 'правило для custom вернёт затирание цвета');
});

test('Наложение названо наложением: оно и затемняет, и осветляет', function () {
    foreach (['form.php', 'slide_form.php'] as $file) {
        $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/' . $file);
        assert_contains('аложени', $form, $file . ': поле снова названо только затемнением');
    }
    $slide = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/slide_form.php');
    assert_contains('светлый осветляет', $slide, 'из подсказки пропало, что светлый цвет осветляет кадр');
});
