<?php

declare(strict_types=1);

use App\Core\DesignSettings;

/*
 * Настройки «Масштаб типографики» больше нет.
 *
 * У неё не было второго состояния: вариант «Плавающие» не описан в CSS нигде,
 * а «Точные» вешали на <body> класс `design-type-static`, под который было
 * написано 99 правил — и все они повторяли размеры, уже заданные компонентам.
 * Замерено переключением на восьми страницах: снятие класса меняло **ноль**
 * элементов. Настройка обещала выбор, которого нет, а её класс поднимал вес
 * селектора на целый класс (`body.design-type-static h2` весит больше любого
 * компонентного правила) — из-за этого заголовки карточек однажды уже
 * получали размер H2.
 *
 * Правила остались, но без режимного класса: размеры те же, вес селектора
 * теперь равен весу самого компонента.
 */

test('Настройки «Масштаб типографики» нет ни в реестре, ни в форме', function () {
    assert_false(
        array_key_exists('type_scale', DesignSettings::OPTIONS),
        'настройка вернулась в реестр «Дизайна»'
    );

    foreach (DesignSettings::PRESETS as $slug => $preset) {
        assert_false(
            array_key_exists('type_scale', (array) ($preset['values'] ?? [])),
            'пресет ' . $slug . ' всё ещё задаёт удалённую настройку'
        );
    }

    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/design/index.php');
    assert_not_contains('type_scale', $view, 'поле осталось в форме «Дизайна»');
});

test('Класс design-type-static не печатается и не встречается в CSS', function () {
    $classes = DesignSettings::bodyClasses(DesignSettings::PRESETS['classic']['values']);
    assert_not_contains('design-type-static', $classes);

    $root = APP_ROOT . '/public/assets/css';
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.css') || str_contains($path, 'admin')) {
            continue;
        }
        $css = (string) file_get_contents($path);
        assert_false(
            str_contains($css, 'body.design-type-static'),
            'режимный класс остался в ' . basename($path)
        );
    }
});

test('Мобильные размеры сохранили вес селектора', function () {
    // «Дизайн» печатает точные размеры заголовков переменными
    // (`--font-size-h1` и далее), и правила редакционных страниц читают их
    // через var(): без довеса телефон получал бы десктопные 42px. Раньше вес
    // давал режимный класс (два класса против одного), теперь — `[class]`
    // у <body>, где классы «Дизайна» есть всегда. Замерено: снятие довеса
    // возвращало 42px в заголовке страницы и 40px в цифрах счётчиков.
    foreach ([
        'public/assets/css/gov-theme.css',
        'public/assets/css/blocks/catalog.css',
        'public/assets/css/blocks/counters.css',
        'public/assets/css/blocks/news-detail.css',
    ] as $file) {
        $css = (string) file_get_contents(APP_ROOT . '/' . $file);
        assert_contains('body[class] ', $css, basename($file) . ': мобильные размеры остались без веса');
    }
});

test('Размеры, державшиеся на режимном классе, остались у самих компонентов', function () {
    // Эти четыре правила отличались от компонентных: класс давал им вес, и без
    // переноса значения вид бы поехал (заголовок каталога и цифры счётчиков
    // вернулись бы к плавающему clamp).
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    $catalog = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/catalog.css');
    $counters = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');

    assert_contains('.timeline-cta__title { font-size: var(--step-5);', $theme);
    assert_contains('font-weight: 700; font-size: var(--step-5);', $theme); // .newslist-lead__title
    assert_contains('.catdetail__title { margin: 12px 0 10px; font-size: var(--step-8);', $catalog);
    assert_not_contains('clamp(var(--step-6), 3.5vw, var(--step-8))', $counters);
});
