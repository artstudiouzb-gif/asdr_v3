<?php

declare(strict_types=1);

test('Материал без перевода не подменяется базовым языком автоматически', function (): void {
    $notice = (string) file_get_contents(APP_ROOT . '/app/Core/ContentLanguageNotice.php');
    assert_contains('Locale::current()', $notice);
    assert_contains('LocalePreference::QUERY', $notice);
    assert_contains('Locale::setContentLangs($availableCodes)', $notice);
    assert_contains("http_response_code(404)", $notice);
    assert_contains("View::render('site/translation_unavailable'", $notice);

    foreach (['PageController.php', 'ProjectController.php', 'NewsController.php', 'AlbumController.php', 'ContentController.php'] as $controller) {
        $source = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/' . $controller);
        assert_contains('ContentLanguageNotice::renderIfMissing', $source, $controller);
    }
});

test('Уведомление об отсутствующем переводе локализовано', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/translation_unavailable.php');
    assert_contains("t('Перевод пока недоступен')", $view);
    assert_contains('hreflang=', $view);
    assert_contains('$robotsNoindex = true', $view);

    foreach (['uz', 'en'] as $lang) {
        $dict = require APP_ROOT . '/app/Core/lang/' . $lang . '.php';
        assert_true(isset($dict['Перевод пока недоступен']), $lang);
        assert_true(isset($dict['Посмотреть на языке']), $lang);
    }
});

test('Переключатель языка не скрывает активные языки без перевода', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    assert_contains('$activeLangs = Language::active()', $header);
    assert_contains('$hrefLangs = $activeLangs', $header);
    assert_contains('foreach ($activeLangs as $l)', $header);
    assert_contains('foreach ($hrefLangs as $hrefLang)', $header);
});
