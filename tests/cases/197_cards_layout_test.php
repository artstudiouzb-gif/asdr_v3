<?php

declare(strict_types=1);

/**
 * Карточки и токены сетки. Дефекты видны на любом наполнении, поэтому
 * проверяем их по стилям, а не по демо-контенту.
 */

test('Карточки: стрелка не отрывается от заголовка', function () {
    $css = theme_css();

    // С обычным пробелом стрелка переносилась на следующую строку и липла
    // к тексту — «…рост→».
    assert_contains('content: "\00a0→";', $css);
    assert_false(
        (bool) preg_match('/\.feature-card__title::after \{\s*content: " →"/', $css),
        'стрелка должна отделяться неразрывным пробелом'
    );
});

test('Карточки: номер не спорит с заголовком', function () {
    $css = theme_css();

    // Номер направления был 2.6rem при заголовке 1.12rem. После перевода
    // типографики на шкалу проверяем ступень, а не число.
    assert_true(
        (bool) preg_match('/\.feature-card::after \{.*?font-size: var\((--step-{1,2}\d+)\)/s', $css, $m),
        'размер номера карточки должен браться из шкалы'
    );
    assert_true(
        in_array($m[1], ['--step--4', '--step--3', '--step--2', '--step--1', '--step-0', '--step-1', '--step-2', '--step-3'], true),
        'номер карточки должен быть не крупнее --step-3 (1.4rem), сейчас ' . $m[1]
    );
});

test('Карточки новостей: ровные интервалы вместо растянутого промежутка', function () {
    $css = theme_css();

    // margin-top: auto прижимал анонс к низу, и расстояние «заголовок → анонс»
    // у соседей по ряду отличалось вдвое.
    assert_not_contains('.relnews-card__excerpt { margin-top: auto; }', $css);
    assert_contains('.relnews-card__excerpt { margin-top: 0; }', $css);
    assert_contains('-webkit-line-clamp: 3;', $css);
});

test('Карточки с фото: изображение не окружено внутренней рамкой', function () {
    $css = theme_css();

    assert_true(
        (bool) preg_match('/\.relnews-card\s*\{[^}]*padding:\s*0 0 14px;[^}]*overflow:\s*hidden;/s', $css),
        'фото похожей новости должно начинаться от внешнего контура карточки'
    );
    assert_contains('.relnews-card__media { border-radius: 0; }', $css);
    assert_contains('.album-card__body { padding: 14px; }', $css);
    assert_contains('.catcard--with-image { padding: 0 24px 24px; }', $css);
    assert_contains('margin-inline: -24px;', $css);
});

test('Карточки: материал без изображения получает фирменную подложку', function () {
    $css = theme_css();

    assert_contains('span.imgcard__media', $css);
    assert_contains('mask: var(--gov-emblem) center / 58% no-repeat;', $css);
    assert_contains('mask: var(--gov-emblem) center / 44% no-repeat;', $css);
});

test('Карточки: подпись читается и на светлом фото', function () {
    $css = theme_css();

    // Прежний градиент начинал затемнение только на 30% высоты и оставлял
    // белый заголовок на светлом небе почти без контраста.
    assert_contains('rgba(11,26,48,.55) 58%', $css);
});

test('Токены: один радиус и подвал по числу колонок', function () {
    $base = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');
    $theme = theme_css();

    preg_match('/--radius:\s*(\d+)px/', $base, $baseRadius);
    preg_match('/--radius:\s*(\d+)px/', $theme, $themeRadius);
    assert_same($themeRadius[1] ?? '', $baseRadius[1] ?? '', 'радиус в базе и теме должен совпадать');

    // Жёсткие три колонки оставляли пустую треть, когда настроены две.
    assert_contains('grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));', $base);
});
