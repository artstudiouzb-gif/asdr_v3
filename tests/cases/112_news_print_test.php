<?php

declare(strict_types=1);

test('Печать новости: служебные блоки помечены в шаблоне, а не только в CSS', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/news_show.php');

    // Пометка живёт в разметке: переименование CSS-классов при редизайне не
    // должно снова возвращать на печать подписку и «Другие новости».
    //
    // Проверяем сам инвариант: элемент с базовым классом служебного блока
    // несёт `no-print` в том же атрибуте class. Раньше здесь искалось точное
    // соседство «newsdetail-subscribe no-print», и добавление модификатора
    // (`newsdetail-subscribe--fallback`) ломало тест, хотя разметка была верна.
    // PHP-вставки внутри class="" (условные модификаторы) заменяем пробелом:
    // в разметке они разворачиваются в отдельный класс с ведущим пробелом.
    $markup = (string) preg_replace('/<\?.*?\?>/s', ' ', $view);

    $classAttributes = [];
    if (preg_match_all('/class="([^"]*)"/', $markup, $matches) > 0) {
        $classAttributes = $matches[1];
    }

    foreach ([
        'newsdetail-share' => 'кнопки «Поделиться»',
        'newsdetail-subscribe' => 'блок подписки',
        'newsdetail-related' => 'похожие новости',
        'newsdetail-adjacent' => 'предыдущая/следующая новость',
    ] as $base => $what) {
        $carriers = array_values(array_filter(
            $classAttributes,
            static fn (string $attr): bool => preg_match('/(^|\s)' . preg_quote($base, '/') . '(\s|$)/', $attr) === 1
        ));

        assert_true($carriers !== [], "в разметке нет блока {$base}");
        foreach ($carriers as $attr) {
            assert_true(
                preg_match('/(^|\s)no-print(\s|$)/', $attr) === 1,
                "на печать попадёт {$what}"
            );
        }
    }

    // Сама статья и её содержательные части не помечаются как непечатаемые.
    assert_not_contains('newsdetail-body no-print', $view);
    assert_not_contains('newsdetail-card no-print', $view);
});

test('Печать новости: печатные стили знают текущие классы страницы', function () {
    $printBlock = public_print_css();

    // Общий выключатель для помеченной разметки.
    assert_contains('.no-print', $printBlock);
    // Интерактив внутри статьи на бумаге бесполезен.
    foreach ([
        '.newsdetail-gallery__nav',
        '.newsdetail-toc',
        '.crumbs',
        '.scroll-top',
    ] as $selector) {
        assert_contains($selector, $printBlock, "не скрыт {$selector}");
    }
    // Белый текст обложки на бумаге нечитаем — печатаем чёрным без подложки.
    assert_contains('.newsdetail-phero { background-image: none', $printBlock);
    assert_contains('.newsdetail-phero__title', $printBlock);
    // Колонки статьи на A4 разворачиваются в один поток.
    assert_contains('.newsdetail-body, .newsdetail-head { display: block', $printBlock);
});
