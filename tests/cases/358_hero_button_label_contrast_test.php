<?php

declare(strict_types=1);

use App\Core\AccentContrast;
use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

/*
 * Надпись на кнопке обложки читается на её собственной заливке.
 *
 * `--on-accent` объявлен в `:root` по акценту сайта («Дизайн»), а у обложки
 * есть свой «Цвет основной кнопки» (`scheme_accent`). Пока надпись бралась
 * только из `:root`, свой цвет менял заливку и не менял текст: на светлой
 * заливке (жёлтой, салатовой) оставался белый — 1.1:1.
 *
 * Отдельного поля «цвет текста кнопки» у слайда нет намеренно: цвет надписи
 * не самостоятельное решение, а следствие заливки, и ручной выбор позволил бы
 * собрать нечитаемую пару. Тот же приём, что у акцентной цитаты.
 */

/** @param array<string, mixed> $settings */
function hero_button_css(array $settings): string
{
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Проба'],
        [[
            'id' => 1, 'hero_id' => 1, 'title' => 'Т', 'sort_order' => 0, 'is_active' => 1,
            'data' => HeroSlideData::withDefaults([
                'title' => 'Заголовок',
                'cta_enabled' => true, 'cta_text' => 'Кнопка', 'cta_url' => '/', 'cta_style' => 'primary',
            ]),
        ]],
        HeroSettings::withDefaults($settings),
        7
    );

    return (string) ($rendered['css'] ?? '');
}

test('Цвет надписи кнопки обложки считается по её заливке', function () {
    // Светлая заливка: белая надпись на ней нечитаема, значит текст тёмный.
    $light = hero_button_css(['scheme_accent' => '#ffe066']);
    assert_contains('--hero-accent:#ffe066', $light, 'свой цвет кнопки обязан доехать до вывода');
    assert_contains(
        '--on-accent:' . AccentContrast::onFill('#ffe066'),
        $light,
        'на светлой заливке надпись обязана становиться тёмной'
    );
    assert_not_contains('--on-accent:#ffffff', $light, 'белым по светло-жёлтому надпись не видна');

    // Тёмная заливка: остаётся светлой.
    $dark = hero_button_css(['scheme_accent' => '#0b3a6b']);
    assert_contains('--on-accent:#ffffff', $dark, 'на тёмной заливке надпись обязана оставаться светлой');
});

test('Без своего цвета кнопки обложка не трогает --on-accent', function () {
    // Заливка приходит из «Дизайна» (`--gov-teal`), и надпись к ней уже
    // посчитана в `:root`. Переобъявлять её здесь нечем — цвета акцента на
    // сервере в этот момент нет, а вычислить контраст из `var(...)` нельзя.
    $css = hero_button_css([]);
    assert_contains('--hero-accent:var(--gov-teal)', $css, 'без своего цвета кнопка берёт акцент сайта');
    assert_not_contains('--on-accent', $css, 'значение из :root перекрывать нечем и незачем');
});
