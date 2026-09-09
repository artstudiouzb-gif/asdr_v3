<?php

declare(strict_types=1);

use App\Core\FooterConfig;

test('FooterConfig: колонки валидируются, мусор-виджеты отброшены, лимит 4', function () {
    $cfg = FooterConfig::normalize([
        'style' => 'columns',
        'columns' => [
            ['heading' => 'A', 'widget' => 'about'],
            ['heading' => 'M', 'widget' => 'нечто'],   // неизвестный виджет — выброс
            ['heading' => 'C', 'widget' => 'contacts'],
            ['heading' => 'S', 'widget' => 'social'],
            ['heading' => 'T', 'widget' => 'text', 'text' => 'x'],
            ['heading' => 'X', 'widget' => 'menu'],     // 5-я валидная — за лимитом
        ],
        'bottom' => '',
    ]);

    $widgets = array_map(fn ($c) => $c['widget'], $cfg['columns']);
    assert_same(['about', 'contacts', 'social', 'text'], $widgets, 'мусор убран, лимит 4 колонки');
    assert_same('© {year} {site}', $cfg['bottom'], 'пустая нижняя строка → дефолт');
});

test('FooterConfig: текстовый виджет санируется, прочие не несут text', function () {
    $cfg = FooterConfig::normalize([
        'columns' => [
            ['heading' => 'T', 'widget' => 'text', 'text' => '<p>ok</p><script>alert(1)</script>'],
            ['heading' => 'M', 'widget' => 'menu', 'text' => '<b>лишнее</b>'],
        ],
    ]);
    assert_true(!str_contains($cfg['columns'][0]['text'], '<script'), 'скрипт вырезан');
    assert_contains('<p>ok</p>', $cfg['columns'][0]['text'], 'безопасный HTML сохранён');
    assert_same('', $cfg['columns'][1]['text'], 'не-текстовый виджет не хранит text');
});

test('FooterConfig::renderBottom разворачивает {year} и {site}', function () {
    $out = FooterConfig::renderBottom('© {year} {site} · Все права', 'Мой Сайт');
    assert_contains(date('Y'), $out);
    assert_contains('Мой Сайт', $out);
    assert_true(!str_contains($out, '{year}') && !str_contains($out, '{site}'), 'плейсхолдеры заменены');
});

test('FooterConfig: недопустимый стиль → columns', function () {
    assert_same('columns', FooterConfig::normalize(['style' => 'zzz'])['style']);
    assert_same('minimal', FooterConfig::normalize(['style' => 'minimal'])['style']);
});

test('FooterConfig: виджет subscribe валиден', function () {
    $cfg = FooterConfig::normalize(['columns' => [['heading' => 'Подписка', 'widget' => 'subscribe']]]);
    assert_same('subscribe', $cfg['columns'][0]['widget']);
});

test('Footer: нижняя строка использует общую ширину сайта', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    assert_contains('max-width: var(--container-max, 1440px);', $css);
    assert_contains('padding: 0 20px;', $css);
    assert_not_contains('max-width: var(--content-width, 1200px);', $css);
});

test('FooterConfig: «Логотип и описание» хранит свой текст, прочие виджеты — нет', function () {
    $cfg = FooterConfig::normalize([
        'v' => FooterConfig::VERSION,
        'columns' => [
            ['heading' => 'A', 'widget' => 'about', 'text' => '<p>подпись</p><script>x</script>'],
            ['heading' => 'S', 'widget' => 'social', 'text' => '<b>лишнее</b>'],
        ],
    ]);
    assert_contains('<p>подпись</p>', $cfg['columns'][0]['text'], 'подпись под знаком сохранена');
    assert_true(!str_contains($cfg['columns'][0]['text'], '<script'), 'скрипт вырезан');
    assert_same('', $cfg['columns'][1]['text'], 'виджету без своего текста он не принадлежит');
});

test('FooterConfig: старому подвалу без «Контактов» колонка добавляется разово', function () {
    // До v2 виджет «about» печатал адрес, телефон и почту сам. Убрав их
    // оттуда, нельзя молча оставить сайт без контактов.
    $legacy = ['columns' => [['heading' => '', 'widget' => 'about'], ['heading' => 'Разделы', 'widget' => 'menu']]];

    $once = FooterConfig::normalize($legacy);
    assert_same(['about', 'menu', 'contacts'], array_column($once['columns'], 'widget'), 'колонка контактов добавлена');
    assert_same(FooterConfig::VERSION, $once['v'], 'результат помечен новой версией');

    // Повторная прогонка уже перенесённого конфига ничего не добавляет —
    // иначе удалённая редактором колонка возвращалась бы при каждом сохранении.
    $again = FooterConfig::normalize(['v' => FooterConfig::VERSION, 'columns' => $once['columns']]);
    $withoutContacts = array_values(array_filter($again['columns'], fn ($c) => $c['widget'] !== 'contacts'));
    $deleted = FooterConfig::normalize(['v' => FooterConfig::VERSION, 'columns' => $withoutContacts]);
    assert_same(['about', 'menu'], array_column($deleted['columns'], 'widget'), 'перенос не повторяется');
});

test('FooterConfig: подвал с обеими колонками не трогаем', function () {
    $cfg = FooterConfig::normalize(['columns' => [
        ['heading' => '', 'widget' => 'about'],
        ['heading' => 'Связь', 'widget' => 'contacts'],
    ]]);
    assert_same(['about', 'contacts'], array_column($cfg['columns'], 'widget'), 'дубля колонки нет');
});

test('Подвал: контакты выводятся один раз и с иконками', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    [, $aboutCase] = explode("case 'about':", $view, 2);
    [$aboutCase] = explode("case 'menu':", $aboutCase, 2);
    foreach (['$address', '$phone', '$email'] as $var) {
        assert_true(!str_contains($aboutCase, $var), 'виджет «about» не печатает ' . $var . ' — это дело «Контактов»');
    }

    [, $contactsCase] = explode("case 'contacts':", $view, 2);
    [$contactsCase] = explode("case 'social':", $contactsCase, 2);
    foreach (['map-pin', 'phone', 'mail'] as $icon) {
        assert_contains("'" . $icon . "'", $contactsCase, 'у строки контакта есть иконка ' . $icon);
    }
    assert_contains('footer-contacts', $contactsCase, 'разметка списка контактов');
    assert_true(
        !str_contains($contactsCase, 'Политика конфиденциальности'),
        'ссылка на политику живёт в нижней строке подвала, дублировать её в контактах незачем'
    );
});

test('Подвал: у новых виджетов есть оформление в обоих слоях CSS', function () {
    $base = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    foreach (['.footer-contacts__item', '.footer-contacts__icon', '.site-footer__about', '.site-footer__text'] as $sel) {
        assert_contains($sel, $base, 'структура ' . $sel . ' описана в базе');
    }
    // Подвал тёмный: без правил темы текст виджетов выходил цветами страницы.
    foreach (['.site-footer--columns .footer-contacts__icon', '.site-footer--columns .site-footer__text'] as $sel) {
        assert_contains($sel, $theme, 'цвет ' . $sel . ' задан темой');
    }
    // Иконка контакта берёт акцент в варианте «на тёмном»: обычный акцент на navy тонет.
    assert_contains('.site-footer--columns .footer-contacts__icon { color: var(--gov-teal-text)', $theme);
});

test('Подвал: меню в две дорожки только в широкой колонке', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    // «Не больше двух, каждая от 220px»: в четырёх колонках подвала дорожка
    // узкая и список идёт одной колонкой, в двух — двумя.
    assert_contains('.site-footer--columns .site-footer__col--menu ul { columns: 2 220px;', $css);
    assert_true(
        !str_contains($css, '.site-footer--columns .site-footer__col--menu ul { columns: 2;'),
        'жёсткого columns: 2 не осталось'
    );
    // На телефоне подвал в одну колонку, дорожка меню во всю ширину — тогда
    // «от 220px» снова даёт две полуколонки, поэтому явно возвращаем одну.
    assert_contains('@media (max-width: 720px) { .site-footer--columns .site-footer__col--menu ul { columns: 1; } }', $css);
});

test('FooterConfig: ссылки нижней строки — подпись, адрес, лимит и проверка href', function () {
    $cfg = FooterConfig::normalize([
        'bottom_links' => [
            ['label' => 'Карта сайта', 'url' => '/sitemap'],
            ['label' => '', 'url' => '/no-label'],                 // без подписи — выброс
            ['label' => 'Без адреса', 'url' => ''],                // без адреса — выброс
            ['label' => 'Дыра', 'url' => 'javascript:alert(1)'],   // небезопасная схема — выброс
            ['label' => 'Условия', 'url' => 'https://example.uz/terms'],
            ['label' => 'Обратная связь', 'url' => '/feedback'],
            ['label' => 'Пятая', 'url' => '/five'],
            ['label' => 'Шестая', 'url' => '/six'],                // за лимитом
        ],
    ]);

    $labels = array_map(fn ($l) => $l['label'], $cfg['bottom_links']);
    assert_same(['Карта сайта', 'Условия', 'Обратная связь', 'Пятая'], $labels, 'мусор убран, лимит 4 ссылки');
    assert_same(FooterConfig::MAX_BOTTOM_LINKS, count($cfg['bottom_links']));
});

test('FooterConfig: ссылок нижней строки по умолчанию нет', function () {
    assert_same([], FooterConfig::normalize([])['bottom_links'], 'без настройки строка копирайта не меняется');
    assert_same([], FooterConfig::normalize(['bottom_links' => 'мусор'])['bottom_links'], 'не-массив игнорируется');
});

test('Подвал: ссылки нижней строки выводятся в обеих раскладках и оформлены', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');

    // Нижняя строка есть у обоих стилей подвала — ссылки обязаны быть в обеих.
    assert_same(2, substr_count($view, '<?= $footerBottomLinks ?>'), 'ссылки выводятся у columns и minimal');
    // Подпись из конструктора переводится, как и заголовки колонок.
    assert_contains('t((string) $footerLink[\'label\'])', $view);
    assert_contains('.site-footer__bottom-links', $css, 'настройка без оформления ничего не меняет');
});

test('Подвал: форма конструктора даёт редактору ссылки нижней строки', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/footer/index.php');

    assert_contains('data-repeater="footlink"', $view);
    assert_contains('data-repeater-max="<?= FooterConfig::MAX_BOTTOM_LINKS ?>"', $view);
    assert_contains('bottom_links[__INDEX__][label]', $view);
    assert_contains('bottom_links[__INDEX__][url]', $view);
});
