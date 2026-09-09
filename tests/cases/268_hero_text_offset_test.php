<?php

declare(strict_types=1);

use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

/*
 * Отступ всего текстового блока обложки сверху. Промежутки gap_* работают
 * между частями, и у первой части сняты правилом `.hero__text > :first-child`,
 * поэтому опустить колонку целиком ими нельзя — до появления настройки это
 * дописывали padding'ом в «Свой CSS» страницы.
 */

/** @param array<string, mixed> $slide */
function hero_offset_css(array $slide, array $settings = []): string
{
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Проба'],
        [['id' => 1, 'hero_id' => 1, 'title' => 'Т', 'sort_order' => 0, 'is_active' => 1,
          'data' => HeroSlideData::withDefaults($slide)]],
        HeroSettings::withDefaults($settings),
        21
    );

    return (string) ($rendered['css'] ?? '');
}

test('Отступ текста сверху доезжает до стилей обложки и телефона', function () {
    $slide = ['title' => 'Заголовок', 'media_type' => 'none'];

    $hero = hero_offset_css($slide, ['text_offset_top' => 40]);
    assert_contains('--hero-text-offset:40px', $hero, 'отступ обложки не попал в стили');

    // Телефон: отдельной настройки нет — десктопное значение ограничивается
    // высотой окна, иначе крупный отступ выдавил бы текст за экран.
    assert_contains(
        '@media (max-width:720px)',
        $hero,
        'мобильное ограничение отступа пропало'
    );
    assert_contains('--hero-text-offset:min(40px,10vh)', $hero, 'на телефоне отступ не ограничен');

    // Нулевой отступ на телефоне ограничивать нечего — лишнего медиазапроса
    // из-за него быть не должно.
    $zero = hero_offset_css($slide, ['text_offset_top' => 0]);
    assert_false(
        str_contains($zero, 'min(0px'),
        'нулевой отступ породил мёртвое объявление в медиазапросе'
    );

    // Отступ принадлежит обложке: у слайда своего больше нет, поэтому кроме
    // десктопного объявления и мобильного ограничения переменной нигде нет.
    assert_same(
        2,
        substr_count($hero, '--hero-text-offset'),
        'отступ объявляется лишний раз — настройка снова живёт в нескольких местах'
    );
});

test('Блок текста обложки читает отступ из переменной', function () {
    // Без этого правила настройка остаётся числом в базе.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    $rule = (string) preg_replace('/^.*?\n\.hero__text \{(.*?)\n\}.*$/s', '$1', $css);
    assert_contains('padding-top: var(--hero-text-offset', $rule, 'блок текста перестал читать отступ');
});

test('Отступ задаётся из форм, а не только из базы', function () {
    $hero = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/form.php');
    assert_contains('name="text_offset_top"', $hero, 'в форме обложки нет поля отступа');
    assert_false(
        str_contains($hero, 'name="text_offset_top_mobile"'),
        'мобильный дубль отступа вернулся в форму'
    );

    // Значение доезжает до данных числом; ни у обложки, ни у слайда второго
    // отступа больше нет.
    $settings = HeroSettings::normalize(['text_offset_top' => '40']);
    assert_same(40, $settings['text_offset_top']);
    assert_false(
        array_key_exists('text_offset_top_mobile', HeroSettings::defaults()),
        'у обложки снова появился отдельный отступ для телефона'
    );

    assert_false(
        array_key_exists('text_offset_top', HeroSlideData::defaults()),
        'у слайда снова появился свой отступ'
    );
});
