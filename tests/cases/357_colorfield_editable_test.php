<?php

declare(strict_types=1);

use App\Core\BlockData\HeroBlockNormalizer;
use App\Core\Hero\HeroSettings;

/*
 * Поле цвета обязано быть рабочим и в состоянии «по умолчанию».
 *
 * `AdminUi::colorField()` рисует образец и галочку «по умолчанию» (<имя>_off),
 * а сервер читает галочку раньше значения. Пока скрипт панели ещё и выключал
 * сам образец (`disabled`), поле не отзывалось на нажатие вовсе: у обложки
 * «Цвет текста» и «Цвет фона основной кнопки» приходят с включённой галочкой,
 * то есть поменять их было нечем — образец не открывался, а о том, что его
 * запирает соседняя галочка, ниоткуда не видно.
 *
 * Договорённость теперь такая: выбор цвета сам означает «не по умолчанию».
 */
test('Образец цвета не выключается, а выбор снимает галочку «по умолчанию»', function () {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    $start = strpos($js, "document.querySelectorAll('.colorfield')");
    assert_true($start !== false, 'в admin.js должен остаться обработчик полей цвета');
    $block = substr($js, (int) $start, 4000);

    assert_not_contains(
        'color.disabled',
        $block,
        'выключенный образец не открывается — поле цвета становится нерабочим'
    );
    assert_contains(
        "color.addEventListener('input'",
        $block,
        'выбор цвета обязан сниматься с состояния «по умолчанию» сам'
    );
    assert_contains(
        'off.checked = false;',
        $block,
        'иначе выбранный цвет уйдёт на сервер вместе с галочкой и будет отброшен'
    );
});

/*
 * Обратная сторона того же: раз образец теперь отправляется всегда, значение
 * рядом с включённой галочкой не должно доезжать до данных.
 */
test('Галочка «по умолчанию» читается раньше значения — и у блока, и у обложки', function () {
    $block = HeroBlockNormalizer::normalize([
        'text_color' => '#FF0000',
        'button_color' => '#00FF00',
    ], 'ru');
    assert_same('#ff0000', $block['text_color'], 'цвет текста обложки-блока обязан сохраняться');
    assert_same('#00ff00', $block['button_color'], 'цвет кнопки обложки-блока обязан сохраняться');

    $cleared = HeroBlockNormalizer::normalize([
        'text_color' => '#FF0000',
        'text_color_off' => '1',
        'button_color' => '#00FF00',
        'button_color_off' => '1',
    ], 'ru');
    assert_same('', $cleared['text_color'], 'галочка «Авто» обязана отменять присланный цвет');
    assert_same('', $cleared['button_color'], 'галочка «По умолчанию» обязана отменять присланный цвет');

    $hero = HeroSettings::normalize(['scheme_accent' => '#FF0000']);
    assert_same('#ff0000', $hero['scheme_accent'], 'цвет основной кнопки обложки обязан сохраняться');

    $heroOff = HeroSettings::normalize(['scheme_accent' => '#FF0000', 'scheme_accent_off' => '1']);
    assert_same('', $heroOff['scheme_accent'], 'галочка «Акцент из «Дизайна»» обязана отменять присланный цвет');
});
