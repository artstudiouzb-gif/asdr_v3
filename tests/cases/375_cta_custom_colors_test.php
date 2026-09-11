<?php

declare(strict_types=1);

/*
 * Свои цвета блока «Призыв к действию» действуют.
 *
 * Поля «Цвет фона / текста / кнопки» есть у всех вариантов блока (карточка,
 * полоса, баннер) и сохранялись, но на сайте не меняли ничего: правила темы
 * включались по инлайн-стилю (`.block-cta[style*="--cta-bg"]`), а инлайн-стилей
 * в блоках нет — их запрещают тесты, переменные давно уезжают в scoped CSS.
 * Признак «цвет выбран» теперь класс на самом элементе.
 *
 * Второе: цвет текста при своём фоне считается по контрасту (как у акцентной
 * цитаты и подложки новостей). Белый заголовок на светлой заливке не читается
 * вовсе, а узнать об этом редактору было неоткуда.
 */

/** @return array{0:string,1:string} разметка и scoped CSS блока */
function cta_render(array $overrides): array
{
    $data = \App\Core\BlockData\BlockFieldSchema::apply('cta', array_merge(
        \App\Core\BlockTypeRegistry::defaultsFor('cta'),
        ['title' => 'Заголовок', 'text' => 'Текст', 'button_text' => 'Кнопка', 'button_url' => '/x'],
        $overrides
    ));

    $render = static function (string $__file, array $data, int $blockId): array {
        $templateCss = '';
        ob_start();
        require $__file;

        return [(string) ob_get_clean(), $templateCss];
    };

    return $render((string) \App\Core\BlockTypeRegistry::templateFile('cta'), $data, 7);
}

test('Свой цвет включает правило классом, а не инлайн-стилем', function () {
    foreach ([
        ['card', 'block-cta', 'cta'],
        ['band', 'block-ctaband', 'ctaband'],
        ['media-dark', 'block-banner', 'banner'],
        ['media-light', 'block-banner', 'banner'],
    ] as [$variant, $block, $prefix]) {
        [$html, $css] = cta_render(['variant' => $variant, 'bg_color' => '#123456', 'button_color' => '#abcdef']);

        assert_contains($block . '--custom-bg', $html, $variant . ': класс фона на элементе');
        assert_contains($block . '--custom-btn', $html, $variant . ': класс кнопки на элементе');
        assert_contains('--' . $prefix . '-bg:#123456', $css, $variant . ': переменная в scoped CSS');
    }

    // Без выбранных цветов блок остаётся прежним: ни классов, ни своего CSS.
    [$plain, $plainCss] = cta_render(['variant' => 'card']);
    assert_not_contains('--custom-', $plain, 'без настройки классов нет');
    assert_same('', $plainCss, 'без настройки своего CSS нет');
});

test('Цвет текста при своём фоне считается по контрасту', function () {
    [, $light] = cta_render(['variant' => 'card', 'bg_color' => '#f5e9c8']);
    [, $dark] = cta_render(['variant' => 'card', 'bg_color' => '#0b2d5c']);

    assert_contains('--cta-text:#0b1a30', $light, 'на светлой заливке текст тёмный');
    assert_contains('--cta-text:#ffffff', $dark, 'на тёмной заливке текст светлый');

    // Явно выбранный цвет главнее подбора — это решение редактора.
    [, $chosen] = cta_render(['variant' => 'card', 'bg_color' => '#0b2d5c', 'text_color' => '#ffd166']);
    assert_contains('--cta-text:#ffd166', $chosen, 'свой цвет текста побеждает');

    // У подписи кнопки та же беда: белым по светлой кнопке не прочесть.
    [, $btn] = cta_render(['variant' => 'card', 'button_color' => '#ffd166']);
    assert_contains('--cta-btn-fg:#0b1a30', $btn, 'подпись кнопки тоже по контрасту');
});

test('Тема слушает классы-признаки, а не инлайн-стиль', function () {
    $css = \theme_css();

    foreach ([
        '.block-cta--custom-bg',
        '.block-cta--custom-text',
        '.block-cta--custom-btn',
        '.block-banner--custom-bg',
        '.block-banner--custom-text',
        '.block-banner--custom-btn',
        '.block-ctaband--custom-bg',
        '.block-ctaband--custom-text',
        '.block-ctaband--custom-btn',
    ] as $selector) {
        assert_contains($selector, $css, $selector . ' объявлен в теме');
    }

    // Правило по инлайн-стилю не сработало бы никогда: инлайна в блоках нет.
    // Комментарии вырезаем — в них приём описан как раз для того, чтобы его
    // не вернули обратно.
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    assert_false(
        (bool) preg_match('/\[style\*="--(?:cta|ctaband|banner)/', $rules),
        'гейтов по инлайн-стилю не осталось'
    );
});

test('Прожектор под курсором рисуется там же, где считаются координаты', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');

    // Три списка в CSS (само свечение, показ по наведению и отключение на
    // касании) описывают один приём и обязаны совпадать: пропуск в любом из
    // них — это либо подсветка, которая не гаснет на телефоне, либо карточка
    // с подготовленным ::before, которое никогда не показывается.
    $list = static function (string $pattern) use ($css): array {
        assert_true((bool) preg_match($pattern, $css, $m), 'список прожектора найден');
        preg_match_all('/[.\w -]*?\.[\w-]+(?=:(?:hover)?:?:before)/', $m[1], $all);

        return array_values(array_unique(array_map(
            static fn (string $selector): string => trim(str_replace(':hover', '', $selector)),
            $all[0]
        )));
    };

    $paint = $list('/((?:[^{}]*::before,\s*)+[^{}]*::before)\s*\{[^{}]*radial-gradient\(220px circle at var\(--mouse-x/');
    $hover = $list('/((?:[^{}]*:hover::before,\s*)+[^{}]*:hover::before)\s*\{\s*opacity: 1;/');

    assert_same($paint, $hover, 'свечение и его показ описаны одним набором');
    assert_contains('.icon-text__card::before', $css, 'карточка «Иконки и текста» получает свечение');
    assert_contains('.icon-text__card:hover::before', $css, 'и показывает его по наведению');

    // Координаты курсора ставит JS: селектор, которого нет в его списке, дал бы
    // свечение, навсегда застывшее в левом верхнем углу карточки.
    assert_contains('.block-icon-text--cards .icon-text__card', $js, 'координаты считаются и для неё');

    // ::before с inset: 0 требует своего контекста позиционирования.
    assert_true(
        (bool) preg_match('/\.icon-text__card \{[^}]*position: relative/s', \theme_css()),
        'у карточки свой контекст позиционирования'
    );
});

test('«Иконка и текст» участвует в появлении карточек по очереди', function () {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    $markup = (string) file_get_contents(APP_ROOT . '/templates/blocks/icon_text.php');

    // Очередь строится по списку сеток: имя сетки блока обязано в нём быть,
    // иначе настройка «Карточки по очереди» молча откатывается на обычное
    // появление секции целиком.
    assert_contains('icon-text__grid', $markup, 'сетка блока называется так');
    assert_contains('.icon-text__grid,', $js, 'сетка перечислена в GRIDS');
});
