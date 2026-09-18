<?php

declare(strict_types=1);

use App\Core\BlockData\HeroBlockNormalizer;
use App\Core\BlockTypeRegistry;

/*
 * Размер зоны иконки в кнопках «Героя» — настройка, а не число в правиле.
 *
 * 46px было записано в трёх правилах темы сразу и ещё дважды в шаблоне
 * (атрибуты width/height у <img> и размер, с которым рисуется <svg>), и
 * поменять его было нечем: иконка ведомства и стрелка «далее» просят разного
 * размера при одной и той же кнопке.
 *
 * Умолчание 0 означает «как в теме»: у «Героев», собранных до появления
 * настройки, своего CSS от неё не появляется и вид не меняется.
 */

/** @return array{0:string,1:string} разметка и scoped CSS блока */
function hero_icon_render(array $overrides): array
{
    $data = HeroBlockNormalizer::normalize(array_merge(
        BlockTypeRegistry::defaultsFor('hero'),
        ['title_field' => 'Заголовок', 'button_text' => 'Кнопка', 'button_url' => '/x', 'button_icon' => 'download'],
        $overrides
    ), 'ru');

    $render = static function (string $__file, array $data, int $blockId): array {
        $templateCss = '';
        ob_start();
        require $__file;

        return [(string) ob_get_clean(), $templateCss];
    };

    return $render((string) BlockTypeRegistry::templateFile('hero'), $data, 9);
}

test('Размер иконки кнопки «Героя» задаётся настройкой и доезжает до вывода', function (): void {
    [$html, $css] = hero_icon_render(['button_icon_size' => 28]);

    assert_contains('--hero-btn-icon:28px', $css, 'размер уходит переменной в scoped CSS');
    assert_contains('width="28" height="28"', $html, 'иконка рисуется тем же числом');

    // Разметка и правило обязаны совпадать: атрибуты резервируют место до
    // загрузки, правило рисует размер — разъехавшись, они дали бы прыжок
    // раскладки ровно на первом экране.
    assert_false(str_contains($html, 'width="46"'), 'прежний размер в разметке не остался');
});

test('Без настройки «Герой» остаётся прежним и своего CSS не получает', function (): void {
    [$html, $css] = hero_icon_render([]);

    assert_contains('width="46" height="46"', $html, 'умолчание — прежние 46px');
    assert_false(str_contains($css, '--hero-btn-icon'), 'объявления при размере темы нет');
});

test('Настройка ограничена сверху нормализатором, а не доверием к форме', function (): void {
    $huge = HeroBlockNormalizer::normalize(['button_icon_size' => '999'], 'ru');
    $negative = HeroBlockNormalizer::normalize(['button_icon_size' => '-40'], 'ru');

    assert_same(72, $huge['button_icon_size'], 'значение выше предела срезается');
    assert_same(0, $negative['button_icon_size'], 'отрицательное читается как «как в теме»');
});

test('Тема читает переменную, а не повторяет число', function (): void {
    $theme = \theme_css();

    assert_true(
        (bool) preg_match_all('/var\(--hero-btn-icon, 46px\)/', $theme, $m) && count($m[0]) >= 6,
        'зона иконки, её svg и картинка берут размер из переменной'
    );

    // Правило зоны иконки не должно вернуться к числу: тогда настройка снова
    // перестанет что-либо менять, ничего об этом не сообщив.
    assert_false(
        (bool) preg_match('/\.block-hero__button-icon[^{}]*\{[^{}]*width:\s*46px/', $theme),
        'размер зоны иконки не записан числом'
    );
});

test('Поле размера есть в форме блока «Герой»', function (): void {
    // «Герой» — один из четырёх типов вне схемы полей, поэтому поле объявлено
    // вручную, и без него настройка была бы мёртвой: данные есть, показать
    // редактору нечем.
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');

    assert_contains('name="button_icon_size"', $form, 'поле объявлено в форме');
    assert_contains('max="72"', $form, 'предел формы совпадает с пределом нормализатора');
});
