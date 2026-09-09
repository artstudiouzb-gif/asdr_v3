<?php

declare(strict_types=1);

use App\Core\AdminUi;
use App\Core\HeaderConfig;

test('AdminUI: все явно запрошенные иконки существуют в едином реестре', function (): void {
    $adminDir = APP_ROOT . '/app/Views/admin';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($adminDir));
    $iconNames = [];

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        assert_true(is_string($source));
        preg_match_all("/AdminUi::icon\\('([^']+)'/", $source, $matches);
        $iconNames = array_merge($iconNames, $matches[1] ?? []);
    }

    foreach (array_unique($iconNames) as $name) {
        assert_true(AdminUi::icon($name) !== '', "Не зарегистрирована иконка {$name}");
    }
});

test('AdminUI: конструктор шапки покрыт тем же реестром иконок', function (): void {
    $aliases = ['language' => 'globe'];
    foreach (array_keys(HeaderConfig::ELEMENTS) as $elementType) {
        $iconName = $aliases[$elementType] ?? $elementType;
        assert_true(AdminUi::icon($iconName) !== '', "Нет иконки элемента шапки {$elementType}");
    }
});

test('AdminUI: общая навигация и основные редакторы не содержат локальных SVG-реестров', function (): void {
    $files = [
        APP_ROOT . '/app/Views/admin/layout/header.php',
        APP_ROOT . '/app/Views/admin/layout/footer.php',
        APP_ROOT . '/app/Views/admin/header/index.php',
        APP_ROOT . '/app/Views/admin/pages/form.php',
        APP_ROOT . '/app/Views/admin/news/form.php',
        APP_ROOT . '/app/Views/admin/projects/form.php',
        APP_ROOT . '/app/Views/admin/files/index.php',
        APP_ROOT . '/app/Views/admin/design/index.php',
        APP_ROOT . '/app/Views/admin/auth/login.php',
        APP_ROOT . '/app/Views/admin/auth/forgot.php',
        APP_ROOT . '/app/Views/admin/auth/2fa.php',
    ];

    foreach ($files as $file) {
        $source = file_get_contents($file);
        assert_true(is_string($source));
        assert_not_contains('<svg', $source, basename($file));
    }
});

test('AdminUI: админские шаблоны не используют публичные gov-токены', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT . '/app/Views/admin')
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        assert_true(is_string($source));
        assert_not_contains('var(--gov-', $source, $file->getPathname());
    }
});

test('AdminUI: общие варианты кнопок и карточек определены в одной таблице стилей', function (): void {
    $css = file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    assert_true(is_string($css));
    foreach ([
        '.btn--secondary',
        '.btn--warning',
        '.btn--small, .btn--sm',
        '.admin-card-header',
        '.admin-section-icon',
        '.admin-status-card',
    ] as $selector) {
        assert_contains($selector, $css);
    }
});
