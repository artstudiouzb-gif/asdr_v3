<?php

declare(strict_types=1);

use App\Core\HtmlFormatter;
use App\Core\Locale;
use App\Core\OpenGraphHelper;
use App\Core\SeoHelper;

/*
 * «Просмотр кода страницы» и полнота <head>.
 *
 * Разметку собирают три источника с разными привычками — шаблоны с ручными
 * отступами, PHP-конкатенация (шапка и меню — целый узел в одну строку) и кэш
 * блоков, — поэтому исходник выглядел рваным. HtmlFormatter приводит его к
 * читаемому виду, но он не имеет права ничего менять на экране: пробел между
 * строчными элементами виден, между блочными — нет.
 *
 * Здесь же проверяются теги <head>, которых страницам не хватало: описание
 * (оно молча пропадало, если редактор не заполнил поле), языковые версии для
 * соцсетей, даты новости и разметка сайта с поиском.
 */

/** Нормализация, не зависящая от форматтера: пробел у блочных тегов незначим. */
$normalize = static function (string $html): string {
    $block = 'html|head|body|meta|link|title|base|script|style|noscript|template|div|main'
        . '|header|footer|section|article|aside|nav|form|fieldset|legend|h[1-6]|p|blockquote'
        . '|figure|figcaption|ul|ol|li|dl|dt|dd|table|caption|colgroup|col|thead|tbody|tfoot'
        . '|tr|th|td|hr|pre|address|details|summary|dialog';
    $html = (string) preg_replace('/\s+/u', ' ', $html);
    $html = (string) preg_replace('#\s(?=</?(?:' . $block . ')[\s/>])#i', '', $html);
    $html = (string) preg_replace('#(</?(?:' . $block . ')(?:\s[^>]*)?>) #i', '$1', $html);

    return trim($html);
};

$fixture = <<<'HTML'
<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="utf-8"><title>Главная — АСДР</title>
<link rel="stylesheet" href="/a.css"><style>.a{color:red}
.b{color:blue}</style>
</head>
<body class="x"><header class="site-header"><nav class="site-menu"><a class="l" href="/">Главная</a><a class="l" href="/n">Новости</a> <span>метка</span><div class="site-menu__item" data-json="{&quot;a&quot;:1}"><a href="/a">A</a><button type="button">B</button></div></nav></header>
    <main>
        <h1>Заголовок страницы</h1>
        <p>Текст абзаца со <a href="#">ссылкой</a> внутри.</p>
        <pre>  сохранить
   как есть</pre>
        <ul><li>раз</li><li>два</li></ul>
        <textarea>  два  пробела  </textarea>
    </main>
<script>var a = 1 < 2;</script></body></html>
HTML;

test('Форматтер меняет только незначимые пробелы', function () use ($fixture, $normalize): void {
    $out = HtmlFormatter::format($fixture);

    assert_same(
        $normalize($fixture),
        $normalize($out),
        'разметка отличается от исходной чем-то кроме пробелов у блочных тегов'
    );
    assert_same($out, HtmlFormatter::format($out), 'повторное форматирование меняет результат');
});

test('Пробел между строчными элементами не выдумывается и не теряется', function () use ($fixture): void {
    $out = HtmlFormatter::format($fixture);

    // Склеенные ссылки остаются склеенными: перенос строки между ними — это
    // пробел на экране, то есть зазор в меню, которого не было.
    assert_contains('</a><a class="l" href="/n">Новости</a>', $out);
    assert_contains('</a> <span>метка</span>', $out);
    assert_contains('<a href="/a">A</a><button type="button">B</button>', $out);
});

test('Содержимое pre, textarea, style и script не трогается', function () use ($fixture): void {
    $out = HtmlFormatter::format($fixture);

    assert_contains("<pre>  сохранить\n   как есть</pre>", $out);
    assert_contains('<textarea>  два  пробела  </textarea>', $out);
    assert_contains('<script>var a = 1 < 2;</script>', $out);
    assert_contains(".a{color:red}\n.b{color:blue}", $out);
});

test('Структура получает отступы, а строки — без хвостовых пробелов', function () use ($fixture): void {
    $out = HtmlFormatter::format($fixture);

    assert_contains("<html lang=\"ru\">\n  <head>\n    <meta charset=\"utf-8\">", $out);
    assert_contains("\n      <nav class=\"site-menu\">", $out);
    assert_contains('<h1>Заголовок страницы</h1>', $out); // короткий узел не разворачивается
    assert_same(
        0,
        preg_match('/[ \t]+\n/', $out),
        'в исходнике остались хвостовые пробелы'
    );
    // Значение атрибута с кавычками разбирается целиком.
    assert_contains('data-json="{&quot;a&quot;:1}"', $out);
});

test('Форматирование включено для публичных шаблонов и снимается настройкой', function (): void {
    $view = (string) file_get_contents(__DIR__ . '/../../app/Core/View.php');
    assert_contains('HtmlFormatter::enabled()', $view);
    assert_contains('HtmlFormatter::format($html)', $view);

    $performance = (string) file_get_contents(
        __DIR__ . '/../../app/Controllers/Admin/PerformanceController.php'
    );
    assert_contains("Setting::set('perf_pretty_html'", $performance);
});

test('Open Graph объявляет языковые версии и даты материала', function (): void {
    $og = OpenGraphHelper::render(
        'https://example.com',
        'Заголовок',
        'Описание',
        'https://example.com/news/1',
        'ru',
        '',
        'article',
        '2026-07-25 10:00:00',
        '2026-07-26 12:30:00',
        ['ru', 'uz']
    );

    assert_contains('property="og:locale" content="ru_RU"', $og);
    assert_contains('property="og:locale:alternate" content="uz_UZ"', $og);
    assert_not_contains('og:locale:alternate" content="ru_RU"', $og);
    assert_contains('property="article:published_time" content="2026-07-25T10:00:00+05:00"', $og);
    assert_contains('property="article:modified_time" content="2026-07-26T12:30:00+05:00"', $og);
    assert_contains('property="og:url" content="https://example.com/news/1"', $og);
    assert_contains('property="og:description" content="Описание"', $og);

    // Правки не было — вторая дата только запутывала бы агрегатор.
    $same = OpenGraphHelper::render(
        'https://example.com',
        'Заголовок',
        'Описание',
        'https://example.com/news/1',
        'ru',
        '',
        'article',
        '2026-07-25 10:00:00',
        '2026-07-25 10:00:00'
    );
    assert_not_contains('article:modified_time', $same);

    // Обычная страница статьёй не является: дат у неё нет.
    $page = OpenGraphHelper::render(
        'https://example.com',
        'Заголовок',
        'Описание',
        'https://example.com/about',
        'ru',
        '',
        'website',
        '2026-07-25 10:00:00'
    );
    assert_not_contains('article:published_time', $page);
});

test('Описание страницы собирается из текста, когда поле не заполнено', function (): void {
    $html = '<h1>Заголовок</h1><p>Коротко</p>'
        . '<p>Агентство отвечает за подготовку стратегических документов и координацию '
        . 'программ развития регионов республики на среднесрочную перспективу.</p>';

    $description = SeoHelper::autoDescription($html, 120);

    assert_contains('Агентство отвечает', $description);
    assert_not_contains('Коротко', $description);
    assert_not_contains('<', $description);
    assert_true(mb_strlen($description) <= 121, 'описание длиннее запрошенного предела');
    assert_true(str_ends_with($description, '…'), 'обрезка не отмечена многоточием');
    // Обрезка по границе слова: половина слова читается как сбой.
    assert_false(str_contains($description, 'координ…'), 'слово разрезано посередине');

    assert_same('', SeoHelper::autoDescription('<div>Без абзацев</div>'), 'описание взято не из абзаца');
    assert_same('Короткий текст', SeoHelper::clip('Короткий текст', 200));
});

test('Разметка WebSite с поиском выводится только на главной', function (): void {
    Locale::set('ru');

    Locale::setPath('/');
    $home = SeoHelper::websiteSchema('https://example.com', 'АСДР');
    assert_contains('"@type":"WebSite"', $home);
    assert_contains('"@type":"SearchAction"', $home);
    assert_contains('/search?q={search_term_string}', $home);
    assert_contains('"query-input":"required name=search_term_string"', $home);

    Locale::setPath('/about');
    assert_same(
        '',
        SeoHelper::websiteSchema('https://example.com', 'АСДР'),
        'разметка сайта повторяется на внутренней странице'
    );

    Locale::setPath('/');
});
