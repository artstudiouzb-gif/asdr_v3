<?php

declare(strict_types=1);

/*
 * «Показать целиком» у фона обложки — настройка широкого экрана. На телефоне
 * обложка портретная, и вписанный альбомный ролик оставлял сверху и снизу
 * полосы фона: видео не доходило до низа секции. В мобильной раскладке фон
 * обязан заполнять обложку при любой настройке.
 */

test('Обложка: на телефоне фон заполняет секцию и при «Показать целиком»', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.min.css');
    $mobile = '';
    if (preg_match_all('/@media\s*\(max-width:\s*720px\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/', $css, $m)) {
        $mobile = implode("\n", $m[1]);
    }
    assert_true($mobile !== '', 'мобильная раскладка обложки собрана');
    assert_true(preg_match('/(^|[},])\s*\.hero__image\s*,\s*\.hero__video\s*\{[^}]*object-fit:\s*cover/', $mobile) === 1,
        'кадр и ролик на телефоне заполняют обложку');
    assert_true(preg_match('/\.hero__yt--contain\s*\{[^}]*--yt-w:\s*max\(/', $mobile) === 1,
        'ролик YouTube на телефоне тоже заполняет обложку');
});
