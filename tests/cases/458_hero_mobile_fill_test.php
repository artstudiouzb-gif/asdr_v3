<?php

declare(strict_types=1);

use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

/*
 * Фон обложки обязан доходить до её краёв.
 *
 * «Показать целиком» — настройка широкого экрана: на портретной обложке
 * телефона вписанный кадр оставлял полосы фона сверху и снизу.
 *
 * Ролик YouTube — отдельный случай: плеер всегда 16:9, а широкоэкранный кадр
 * он вписывает с чёрными полосами, и «заполнить» тянуло на обложку плеер, а
 * не кадр. Формат кадра называет редактор, CSS покрывает обложку полосой
 * изображения и срезает краем название ролика, которое рисует YouTube.
 */

test('Обложка: «Показать целиком» действует только шире 720px', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.min.css');
    $outside = (string) preg_replace('/@media[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $css);
    assert_true(preg_match('/\.hero__image\s*,\s*\.hero__video\s*\{[^}]*object-fit:\s*cover/', $outside) === 1,
        'без медиазапроса кадр и ролик заполняют обложку');
    assert_true(!str_contains($outside, 'var(--hero-fit)') && !str_contains($outside, '.hero__yt--contain'),
        'выбор «целиком» не действует на телефоне');
    assert_true(preg_match('/@media\s*\(min-width:\s*721px\)\s*\{[^@]*\.hero__yt--contain\s*\{/', $css) === 1,
        '«целиком» у ролика YouTube — только на широком экране');
});

test('Обложка: широкоэкранный ролик YouTube покрывает обложку кадром, а не плеером', function (): void {
    $render = static function (string $frame): string {
        $slide = HeroSlideData::withDefaults([
            'title' => 'Т', 'media_type' => 'youtube',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'youtube_frame' => $frame,
        ]);
        return HeroRenderer::render(['name' => 'Т'], [['data' => $slide]], HeroSettings::withDefaults([]), 58)['css'];
    };
    assert_contains('--yt-ratio:2.4', str_replace(' ', '', $render('2-39')));
    assert_not_contains('--yt-ratio', $render('16-9'), 'обычный формат — умолчание CSS, своей переменной не нужно');
    assert_same('16-9', HeroSlideData::normalize(['youtube_frame' => '3-1'])['youtube_frame'], 'чужой формат — умолчание');

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.min.css');
    assert_true(preg_match('/\.hero__yt\{[^}]*--yt-r:var\(--yt-ratio,\s*1\.7778\)[^}]*--yt-crop:120px/', $css) === 1,
        'размер плеера считается от формата кадра и с запасом на срез названия');
});
