<?php

declare(strict_types=1);

use App\Core\HeaderConfig;

test('HeaderConfig Pro Max: секции top/bottom нормализуются, мусор отброшен', function () {
    $cfg = HeaderConfig::normalize([
        'topbar' => [
            'enabled' => '1', 'style' => 'evil', 'show_mobile' => 1,
            'zones' => ['left' => ['phone', 'email', 'hack'], 'right' => ['language', 'divider', 'divider']],
        ],
        'bottombar' => ['zones' => ['right' => ['search', 'search']]],
        'contacts' => ['phone' => ' +998 71 203 10 00 ', 'email' => 'info@strategy.uz'],
        'snippet' => '<b>Работаем</b><script>alert(1)</script>',
    ]);
    assert_true($cfg['topbar']['enabled'], 'topbar включён');
    assert_same('navy', $cfg['topbar']['style'], 'недопустимый стиль -> navy');
    assert_true($cfg['topbar']['show_mobile']);
    assert_same(['phone', 'email'], $cfg['topbar']['zones']['left'], 'неизвестный элемент отброшен');
    assert_same(['language', 'divider', 'divider'], $cfg['topbar']['zones']['right'], 'divider повторяем');
    assert_same(['search'], $cfg['bottombar']['zones']['right'], 'дубль неповторяемого убран');
    assert_same('+998 71 203 10 00', $cfg['contacts']['phone']);
    assert_contains('<b>Работаем</b>', $cfg['snippet']);
    assert_true(!str_contains($cfg['snippet'], '<script'), 'script вырезан санитайзером');
});

test('HeaderConfig Pro Max: элемент может повторяться в разных секциях', function () {
    $cfg = HeaderConfig::normalize([
        'topbar' => ['enabled' => 1, 'zones' => ['right' => ['language']]],
        'elements' => ['right' => ['language', 'search']],
    ]);
    assert_same(['language'], $cfg['topbar']['zones']['right']);
    assert_same(['language', 'search'], $cfg['elements']['right'], 'уникальность — в пределах секции');
});

test('Topbar: утилитарные иконки наследуют цвет и имеют единый размер', function () {
    $css = theme_css();
    assert_contains('.site-topbar .site-theme-toggle,', $css);
    assert_contains('color: inherit !important;', $css);
    assert_contains('width: 18px !important;', $css);
    assert_contains('height: 18px !important;', $css);
});

test('HeaderConfig: свои фоны секций и тень нормализуются', function () {
    $cfg = HeaderConfig::normalize([
        'middlebar' => ['height' => 'slim', 'bg' => '#AABBCC'],
        'bottombar' => ['bg' => 'red;evil'],
        'shadow' => ['enabled' => '1', 'size' => '999'],
    ]);
    assert_same('#aabbcc', $cfg['middlebar']['bg'], 'hex нормализуется в нижний регистр');
    assert_same('', $cfg['bottombar']['bg'], 'не-hex отброшен');
    assert_true($cfg['shadow']['enabled']);
    assert_same(60, $cfg['shadow']['size'], 'размер тени ограничен лимитом');

    $off = HeaderConfig::normalize([]);
    assert_same('', $off['middlebar']['bg']);
    assert_true($off['shadow']['enabled'] === false);
    assert_same(14, $off['shadow']['size']);
});

test('HeaderConfig: sticky и transparent нормализуются в булевы', function () {
    $cfg = HeaderConfig::normalize(['sticky' => '1', 'transparent' => 'yes']);
    assert_true($cfg['sticky'] === true && $cfg['transparent'] === true);
    $off = HeaderConfig::normalize([]);
    assert_true($off['sticky'] === false && $off['transparent'] === false);
});

test('HeaderConfig: размеры логотипа нормализуются и ограничиваются', function () {
    $cfg = HeaderConfig::normalize(['logo_width' => '320', 'logo_height' => '72']);
    assert_same(320, $cfg['logo_width']);
    assert_same(72, $cfg['logo_height']);

    $clamped = HeaderConfig::normalize(['logo_width' => '9999', 'logo_height' => '1']);
    assert_same(600, $clamped['logo_width']);
    assert_same(20, $clamped['logo_height']);

    $invalid = HeaderConfig::normalize(['logo_width' => '100px', 'logo_height' => '-10']);
    assert_same(240, $invalid['logo_width']);
    assert_same(48, $invalid['logo_height']);
});

test('HeaderConfig: высоты секций и режим линий нормализуются', function () {
    $cfg = HeaderConfig::normalize([
        'topbar' => ['height' => 'tall'],
        'middlebar' => ['height' => 'huge'],
        'bottombar' => ['height' => 'slim'],
        'borders' => 'container',
    ]);
    assert_same('tall', $cfg['topbar']['height']);
    assert_same('normal', $cfg['middlebar']['height'], 'неизвестная высота -> normal');
    assert_same('slim', $cfg['bottombar']['height']);
    assert_same('container', $cfg['borders']);
    assert_same('full', HeaderConfig::normalize(['borders' => 'evil'])['borders']);
});

test('Блок hero: классы ширины и высоты секции', function () {
    $out = \App\Core\BlockRenderer::render(['id' => 60, 'type' => 'hero', 'custom_css' => null, 'data' => json_encode([
        'title' => 'T', 'image' => '/uploads/public/x.jpg', 'width' => 'standard', 'height' => 'full',
    ])])['html'];
    assert_contains('block-hero--w-standard', $out);
    assert_contains('block-hero--h-full', $out);
    $def = \App\Core\BlockRenderer::render(['id' => 61, 'type' => 'hero', 'custom_css' => null, 'data' => json_encode(['title' => 'T'])])['html'];
    assert_contains('block-hero--w-full', $def);
    assert_contains('block-hero--h-regular', $def);

    $custom = \App\Core\BlockRenderer::render(['id' => 62, 'type' => 'hero', 'custom_css' => null, 'data' => json_encode([
        'title' => 'T', 'image' => '/uploads/public/x.jpg', 'height' => 'custom', 'custom_height' => '68.5vh',
    ])]);
    assert_contains('block-hero--h-custom', $custom['html']);
    // Числовые параметры hero публикуются как scoped CSS блока, а не inline —
    // этого требует тест «Динамические параметры hero публикуются как scoped CSS».
    assert_contains('--hero-custom-height:68.5vh', $custom['css']);
    assert_not_contains(' style="', $custom['html']);
});
