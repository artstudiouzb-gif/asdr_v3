<?php

declare(strict_types=1);

use App\Core\LocalePreference;

test('Языковое предпочтение принимает только активный явный выбор', function (): void {
    $active = ['uz', 'ru', 'en'];

    assert_same('ru', LocalePreference::requestedCode('/projects?_lang=ru', $active));
    assert_same(null, LocalePreference::requestedCode('/projects?_lang=de', $active));
    assert_same(null, LocalePreference::requestedCode('/projects?_lang[]=ru', $active));
    assert_same('en', LocalePreference::storedCode(['site_lang' => 'EN'], $active));
    assert_same(null, LocalePreference::storedCode(['site_lang' => 'de'], $active));
});

test('После переключения служебный параметр удаляется, остальные сохраняются', function (): void {
    assert_same('?view=grid&page=2', LocalePreference::querySuffix('/projects?_lang=ru&view=grid&page=2'));
    assert_same('', LocalePreference::querySuffix('/projects?_lang=uz'));
});

test('Языковое cookie не вмешивается в технические маршруты', function (): void {
    foreach (['/admin', '/repo/login', '/assets/app.css', '/health', '/sitemap.xml', '/robots.txt'] as $path) {
        assert_false(LocalePreference::managesPath($path), $path);
    }
    assert_true(LocalePreference::managesPath('/projects'));
    assert_true(LocalePreference::managesPath('/news/item'));
});

test('Переключатель языка передаёт явный выбор роутеру', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $router = (string) file_get_contents(APP_ROOT . '/app/Core/Router.php');

    assert_contains('LocalePreference::QUERY', $header);
    assert_contains('LocalePreference::requestedCode', $router);
    assert_contains('LocalePreference::storedCode', $router);
    assert_contains("header('Cache-Control: private, no-store')", $router);

    $cache = (string) file_get_contents(APP_ROOT . '/app/Core/PublicResponseCache.php');
    assert_contains('LocalePreference::changedThisRequest()', $cache);
});

test('Сохранённый язык перебивает адрес только на корне сайта', function (): void {
    $router = (string) file_get_contents(APP_ROOT . '/app/Core/Router.php');

    // Раньше редирект по cookie срабатывал на любом адресе: ссылка на
    // /uz/news/x уводила человека с русской настройкой на /news/x, а ответ
    // каждой страницы зависел от cookie и не кешировался на CDN.
    assert_contains("\$storedCode !== \$code && \$rest === '/'", $router);
    assert_contains("\$storedCode !== \$defaultCode && \$path === '/'", $router);
});
