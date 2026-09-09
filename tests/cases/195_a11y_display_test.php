<?php

declare(strict_types=1);

use App\Core\A11ySettings;
use App\Core\UzCyrillic;

/**
 * Настройки отображения: размер текста, контраст, шрифт, интервалы, режим
 * чтения и узбекская кириллица. Состояние приходит из cookie и применяется
 * на сервере, поэтому страница сразу отдаётся в нужном виде.
 */

test('Отображение: пустой cookie — обычная страница', function () {
    assert_same(A11ySettings::DEFAULTS, A11ySettings::fromCookie(null));
    assert_same(A11ySettings::DEFAULTS, A11ySettings::fromCookie(''));
    assert_false(A11ySettings::isActive(A11ySettings::DEFAULTS));
    assert_same('', A11ySettings::htmlAttributes(A11ySettings::DEFAULTS));
});

test('Отображение: значения из cookie нормализуются', function () {
    $settings = A11ySettings::fromCookie('size=145&contrast=mono&font=readable&spacing=wider&links=underline');

    // Размер округляется к шагу и не выходит за границы.
    assert_same(150, $settings['size']);
    assert_same('mono', $settings['contrast']);
    assert_same('readable', $settings['font']);
    assert_same('wider', $settings['spacing']);
    assert_same('underline', $settings['links']);

    assert_same(200, A11ySettings::fromCookie('size=900')['size']);
    assert_same(100, A11ySettings::fromCookie('size=10')['size']);
    // Мусор в значениях не должен доезжать до разметки.
    assert_same('normal', A11ySettings::fromCookie('contrast=" onload="alert(1)')['contrast']);
    assert_same('default', A11ySettings::fromCookie('font=comic')['font']);
});

test('Отображение: в разметку попадают только изменённые настройки', function () {
    $attributes = A11ySettings::htmlAttributes(A11ySettings::fromCookie('size=130&reading=on'));

    assert_contains('data-a11y="1"', $attributes);
    assert_contains('data-a11y-size="130"', $attributes);
    assert_contains('data-a11y-reading="on"', $attributes);
    // Значения по умолчанию не печатаются: обычная страница остаётся обычной.
    assert_not_contains('data-a11y-contrast', $attributes);
    assert_not_contains('data-a11y-images', $attributes);
});

test('Отображение: cookie прежней версии переносится, а не сбрасывается', function () {
    $old = A11ySettings::fromCookie('cw:l:on');
    assert_same('mono', $old['contrast']);
    assert_same(150, $old['size']);
    assert_same('on', $old['images']);

    $blue = A11ySettings::fromCookie('bb:xl:off');
    assert_same('warm', $blue['contrast']);
    assert_same(180, $blue['size']);
    assert_same('off', $blue['images']);

    // Посторонняя строка не должна включать режим сама по себе.
    assert_same(A11ySettings::DEFAULTS, A11ySettings::fromCookie('что-то чужое'));
});

test('Кириллица: узбекская латиница переводится по правилам', function () {
    assert_same(
        'Ўзбекистон Республикаси Стратегик режалаштириш ва ислоҳотлар агентлиги',
        UzCyrillic::text('Oʻzbekiston Respublikasi Strategik rejalashtirish va islohotlar agentligi')
    );
    // Диграфы, tutuq belgisi и «э» в начале слова.
    assert_same('шаҳар чироқ ёзув юрак ялпи', UzCyrillic::text('shahar chiroq yozuv yurak yalpi'));
    assert_same('санъат', UzCyrillic::text('sanʼat'));
    assert_same('Экология', UzCyrillic::text('Ekologiya'));
    assert_same('келажак', UzCyrillic::text('kelajak'));
    assert_same('ЙИҒИЛИШ', UzCyrillic::text('YIGʻILISH'));
    // yoʻ нельзя сначала превращать в «ё»: yoʻl → йўл.
    assert_same('йўл Йўл ЙЎЛ', UzCyrillic::text("yoʻl Yo‘l YO'L"));
    assert_same('цемент конституция', UzCyrillic::text('tsement konstitutsiya'));
    // Реальные редакторы и клавиатуры присылают несколько видов апострофа.
    assert_same('ў ғ ў ғ ў ғ', UzCyrillic::text("oʹ gʹ o′ g′ o´ g´"));
});

test('Кириллица: адреса, почта и сущности остаются латиницей', function () {
    assert_same('info@asr.uz', UzCyrillic::text('info@asr.uz'));
    assert_same('https://artstudio.uz/uz', UzCyrillic::text('https://artstudio.uz/uz'));
    assert_contains('&nbsp;', UzCyrillic::text('matn&nbsp;matn'));
    assert_same('матн&#x27;матн', UzCyrillic::text('matn&#x27;matn'));
    assert_same('tel:+998711234567', UzCyrillic::text('tel:+998711234567'));
    assert_same('my.gov.uz', UzCyrillic::text('my.gov.uz'));
});

test('Кириллица: теги, атрибуты и скрипты не переводятся', function () {
    $html = UzCyrillic::html('<a href="/yangiliklar" class="site-menu__link">Yangiliklar</a>');
    assert_contains('href="/yangiliklar"', $html, 'адрес ссылки должен остаться прежним');
    assert_contains('class="site-menu__link"', $html);
    assert_contains('>Янгиликлар<', $html);

    $script = UzCyrillic::html('<script>var shahar = "shahar";</script>');
    assert_same('<script>var shahar = "shahar";</script>', $script);

    // Переключатель письменности сам себя переводить не должен.
    $skip = UzCyrillic::html('<div data-no-translit><span>Lotin</span></div><p>Lotin</p>');
    assert_contains('<span>Lotin</span>', $skip);
    assert_contains('<p>Лотин</p>', $skip);

    $uppercaseSkip = UzCyrillic::html('<div DATA-NO-TRANSLIT>Shahar</div><p>Shahar</p>');
    assert_contains('<div DATA-NO-TRANSLIT>Shahar</div>', $uppercaseSkip);
    assert_contains('<p>Шаҳар</p>', $uppercaseSkip);
    assert_contains(
        '<a href="/uz/news?q=%23o%CA%BBzbekiston2030">#oʻzbekiston2030</a>',
        UzCyrillic::html('<div data-no-translit><a href="/uz/news?q=%23o%CA%BBzbekiston2030">#oʻzbekiston2030</a></div>')
    );
    assert_same('<kbd>Ctrl+K</kbd>', UzCyrillic::html('<kbd>Ctrl+K</kbd>'));
});

test('Кириллица: шрифты поставки объявляют узбекский cyrillic-ext', function () {
    $root = dirname(__DIR__, 2);

    foreach (['noto-sans', 'noto-serif'] as $slug) {
        $css = (string) file_get_contents($root . '/public/assets/css/' . $slug . '.css');
        // Диапазон обрезан со всей исторической кириллицы до современных
        // тюркских букв, поэтому дословного диапазона Google здесь нет.
        assert_contains(
            'unicode-range: U+0490-04FF',
            $css,
            "{$slug}: подмножество cyrillic-ext не объявлено"
        );
    }
});

test('Ни один публичный CSS не ссылается на отсутствующий файл шрифта', function () {
    // Набор шрифтов в поставке менялся, и оборванный url() в @font-face
    // проявляется только 404-м в консоли: текст рисуется системным, а тест
    // на разметку этого не видит.
    $root = dirname(__DIR__, 2);
    $checked = 0;

    foreach ((array) glob($root . '/public/assets/css/*.css') as $stylesheet) {
        if (str_contains(basename((string) $stylesheet), '.min.')) {
            continue;
        }
        $css = (string) file_get_contents((string) $stylesheet);
        preg_match_all("~url\\('(\\.\\./fonts/[^']+)'\\)~", $css, $matches);
        foreach ($matches[1] as $relative) {
            $file = $root . '/public/assets/css/' . $relative;
            assert_true(
                is_file($file) && filesize($file) > 0,
                basename((string) $stylesheet) . ": нет файла {$relative}"
            );
            $checked++;
        }
    }

    // Шесть: два семейства по три подмножества. Вес больше не множит ссылки —
    // файл переменный, и одно лицо объявляет диапазон «400 700».
    assert_true($checked >= 6, "проверено слишком мало ссылок на шрифты: {$checked}");
});

test('Панель: разметка, стили и скрипт согласованы', function () {
    $root = dirname(__DIR__, 2);
    $panel = (string) file_get_contents($root . '/app/Views/site/_a11y_panel.php');
    $js = (string) file_get_contents($root . '/public/assets/js/a11y.js');
    $css = (string) file_get_contents($root . '/public/assets/css/a11y.css');
    $header = (string) file_get_contents($root . '/app/Views/site/_header.php');
    $footer = (string) file_get_contents($root . '/app/Views/site/_footer.php');

    // Панель отдаётся сервером и подключается на всех страницах с шапкой.
    assert_contains('_a11y_panel.php', $footer);
    assert_contains('data-a11y-open', $header);
    assert_contains('A11ySettings::htmlAttributes($a11ySettings)', $header);

    foreach (['data-a11y-range="size"', 'data-a11y-step', 'data-a11y-reset', 'data-a11y-close', 'data-a11y-theme'] as $hook) {
        assert_contains($hook, $panel, "в панели нет {$hook}");
        assert_contains($hook, $js, "скрипт не обрабатывает {$hook}");
    }

    // Каждому шагу размера нужен свой CSS-размер, иначе ползунок «не работает».
    foreach (A11ySettings::sizes() as $size) {
        if ($size === 100) {
            continue;
        }
        assert_contains('html[data-a11y-size="' . $size . '"] { font-size: ' . $size . '%; }', $css);
    }

    // Размер текста — главный элемент панели, и он обязан выдерживать её же
    // настройки. В жёсткой сетке из трёх колонок подписи под кнопками росли
    // вместе с текстом и схлопывали среднюю колонку: при 200 % под значение
    // оставалось 16px, «150 %» превращалось в столбик символов (замерено в
    // Chromium). Поэтому ряд переносится, а ширина значения считается в его
    // собственных знаках, а не в долях панели.
    assert_contains('data-a11y-step="-1"', $panel);
    assert_contains('data-a11y-step="1"', $panel);
    assert_contains('a11y-sizer__value', $panel, 'значение вынесено в крупный вывод');
    assert_true(
        (bool) preg_match('/\.a11y-sizer \{[^}]*flex-wrap: wrap;/', $css),
        'ряд размера переносится, а не сжимает значение'
    );
    assert_true(
        (bool) preg_match('/\.a11y-sizer__value \{[^}]*min-width: 4\.5ch;/', $css),
        'ширина значения задана в его собственных знаках'
    );
    // Круг — цель нажатия: норма 44px, у нас 64 с запасом.
    assert_true(
        (bool) preg_match('/\.a11y-sizer__glyph \{[^}]*width: 64px;/', $css),
        'кнопки размера крупные'
    );
    // Подпись обязана оставаться словом: принудительный перенос рвал
    // «Kattalashtirish» посреди слова.
    assert_true(
        !(bool) preg_match('/\.a11y-sizer__caption \{[^}]*overflow-wrap: anywhere;/', $css),
        'подпись не рвётся посреди слова'
    );

    // Прежняя верхняя полоса заменена выдвижной панелью.
    assert_not_contains('a11y-panel__group', $header);
    assert_not_contains('data-a11y-set="scheme:', $header);
});

test('Переключатель языков: кириллица стоит рядом с узбекским', function () {
    $header = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');

    assert_contains("'Ўзбекча'", $header);
    assert_contains("'/script/cyrl?to=' . rawurlencode(\$uzHref)", $header);
    // На узбекский из кириллицы возвращаемся через тот же переключатель.
    assert_contains("'/script/latn?to=' . rawurlencode(\$href)", $header);
    // Названия языков остаются как написаны: «O‘zbekcha» не транслитерируется.
    assert_contains('data-lang-dropdown data-no-translit', $header);
    assert_contains('class="site-lang-switcher" data-no-translit', $header);
});

test('Переключатель письменности возвращает только на свои адреса', function () {
    $safe = \App\Controllers\Site\ScriptController::safeRedirect(...);

    assert_same('/uz/news?_lang=uz', $safe('/uz/news?_lang=uz'));
    // Чужой адрес, протокол и обход через «//» — это открытый редирект.
    assert_same('/', $safe('https://evil.example/'));
    assert_same('/', $safe('//evil.example/'));
    assert_same('/', $safe('/\\evil.example'));
    assert_same('/', $safe(''));
    // Перевод строки разорвал бы заголовок Location.
    assert_same('/uz/news', $safe("/uz/news\r\nSet-Cookie: a=1"));
});

test('Письменность запоминается, не сбрасывая остальные настройки', function () {
    $before = $_COOKIE[A11ySettings::COOKIE] ?? null;
    $_COOKIE[A11ySettings::COOKIE] = 'size=150&contrast=mono';

    A11ySettings::rememberScript('cyrl');
    $saved = A11ySettings::fromCookie($_COOKIE[A11ySettings::COOKIE]);
    assert_same('cyrl', $saved['script']);
    assert_same(150, $saved['size'], 'размер текста не должен сбрасываться');
    assert_same('mono', $saved['contrast']);

    A11ySettings::rememberScript('чужое значение');
    assert_same('latn', A11ySettings::fromCookie($_COOKIE[A11ySettings::COOKIE])['script']);

    if ($before === null) {
        unset($_COOKIE[A11ySettings::COOKIE]);
    } else {
        $_COOKIE[A11ySettings::COOKIE] = $before;
    }
});

test('Кириллица применяется только к публичным страницам на узбекском', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/View.php');

    assert_contains('UzCyrillic::html($html)', $view);
    assert_contains("Locale::current() !== 'uz'", $view);
    assert_contains("str_starts_with(\$template, 'site/')", $view);
});
