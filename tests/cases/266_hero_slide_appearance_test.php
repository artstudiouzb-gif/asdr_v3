<?php

declare(strict_types=1);

use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

/*
 * Оформление слайда обложки. Поля жили в модели и рисовались рендером, но до
 * них нельзя было дотянуться из админки, а «Цветовая схема» слайда не красила
 * его фон — и то, и другое молчит: форма выглядит рабочей, слайд выглядит
 * нормальным, просто настройка ничего не меняет.
 */

test('У слайда осталось только то, что зависит от кадра', function () {
    // Раскладка, размеры и палитра принадлежат обложке: у слайда они
    // удваивали настройку и разъезжались с ней. От кадра к кадру меняются
    // фотография, наложение поверх неё и цвет текста — они и остались.
    $fields = array_keys(HeroSlideData::defaults());

    foreach (['overlay', 'overlay_color', 'overlay_opacity', 'overlay_direction', 'content_scheme'] as $keep) {
        assert_true(in_array($keep, $fields, true), 'у слайда пропала настройка кадра: ' . $keep);
    }

    foreach ([
        'scheme', 'scheme_bg', 'scheme_text', 'scheme_accent', 'panel',
        'text_position', 'text_align_y', 'title_size', 'subtitle_size',
        'text_offset_top',
    ] as $gone) {
        assert_false(in_array($gone, $fields, true), 'переопределение вернулось к слайду: ' . $gone);
    }
});

test('Цвет наложения у слайда задаётся из формы и доезжает до данных', function () {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/slide_form.php');
    assert_contains("'overlay_color'", $form, 'в форме слайда нет цвета наложения');
    assert_contains('Использовать общую настройку обложки', $form, 'наследование потеряло единую формулировку');

    $out = HeroSlideData::normalize(['overlay' => 'solid', 'overlay_color' => '#001122'], 'ru');
    assert_same('#001122', $out['overlay_color']);
    assert_same('', HeroSlideData::normalize([], 'ru')['overlay_color'], 'пусто — «как у обложки»');
});

test('Вьюхи админки не читают настроек, которых больше нет', function () {
    // Убранная настройка живёт в разметке дольше, чем в коде: PHP молчит про
    // несуществующий ключ до самого запроса, а ErrorHandler превращает его в
    // 500. Так список слайдов упал после того, как у слайда не стало своей
    // схемы: `$data['scheme']` осталось в метке под заголовком.
    $slideKeys = array_keys(HeroSlideData::defaults());
    $heroKeys = array_keys(HeroSettings::defaults());

    foreach ((array) glob(APP_ROOT . '/app/Views/admin/heroes/*.php') as $file) {
        $src = (string) file_get_contents((string) $file);
        $name = basename((string) $file);

        // Читаем только обращения без запасного значения: `?? '...'` —
        // осознанная страховка для старых записей, а не забытый ключ.
        preg_match_all('/\$data\[\'([a-z0-9_]+)\'\](\s*\?\?)?/', $src, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            if (($hit[2] ?? '') !== '') {
                continue;
            }
            assert_true(
                in_array($hit[1], $slideKeys, true),
                $name . ': $data[\'' . $hit[1] . '\'] — у слайда такой настройки нет'
            );
        }

        preg_match_all('/\$settings\[\'([a-z0-9_]+)\'\](\s*\?\?)?/', $src, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            if (($hit[2] ?? '') !== '') {
                continue;
            }
            assert_true(
                in_array($hit[1], $heroKeys, true),
                $name . ': $settings[\'' . $hit[1] . '\'] — у обложки такой настройки нет'
            );
        }
    }
});
