<?php

declare(strict_types=1);

test('дизайн сайта показывает все глобальные поля и доступные вкладки', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/design/index.php');

    assert_contains('name="heading_line_height"', $view);
    // Настройки «Масштаб типографики» больше нет: у неё не было второго
    // состояния — варианта «Плавающие» в CSS не существовало (тест 310).
    assert_not_contains('name="type_scale"', $view);
    assert_contains('data-design-tabs', $view);
    assert_contains('role="tabpanel"', $view);
    assert_contains('aria-selected="true"', $view);
    assert_contains('data-design-page-preview', $view);
    assert_contains('data-design-code-preview', $view);
    assert_contains('href="/admin/header"', $view);
    assert_contains('href="/admin/footer"', $view);
    assert_not_contains('<script nonce=', $view);
});

test('частичная форма дизайна не сбрасывает отсутствующие опции', function (): void {
    $settings = (string) file_get_contents(APP_ROOT . '/app/Core/DesignSettings.php');

    assert_contains("if (!array_key_exists(\$key, \$input))", $settings);
    assert_contains("'heading_line_height_custom' => Setting::get", $settings);
    assert_contains("'space_small' => \$spacings['space_small']", $settings);
    assert_contains("preg_replace('/[^\\p{L}\\p{N}]+/u'", $settings);
});

test('шапка и подвал используют собственные конструкторы как источник истины', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    $settings = (string) file_get_contents(APP_ROOT . '/app/Core/DesignSettings.php');

    assert_contains("\$searchConfig = (array) (\$hcfg['search'] ?? [])", $header);
    assert_contains("\$searchPlaceholder", $header);
    assert_not_contains("\$designVals['search_type']", $header);
    assert_contains("\$designBodyClass .= ' design-search-' . \$searchType", $header);
    assert_contains("\$footerStyle = \$footerCfg['style'] ?? 'columns';", $footer);
    assert_not_contains("DesignSettings::current()['footer_style']", $footer);
    assert_not_contains('design-header-%s', $settings);
    assert_not_contains('design-footer-%s', $settings);
    assert_not_contains('design-mmenu-%s', $settings);
    assert_contains(")) . ' design-mmenu-burger'", $settings);
});

test('интерфейс дизайна имеет адаптивные вкладки и общий JS-контроллер', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    assert_contains('.admin-tab-nav {', $css);
    assert_contains('overflow-x: auto;', $css);
    assert_contains('.design-card:has(input:focus-visible)', $css);
    assert_contains('function initDesignBuilder()', $js);
    assert_contains("localStorage.setItem(storageKey, targetId)", $js);
    assert_contains("event.key === 'ArrowRight'", $js);
    assert_contains("new URLSearchParams(new FormData(form))", $js);
});
