<?php

declare(strict_types=1);

use App\Core\SeoHelper;

test('SEO helper формирует resource hints, схемы и favicon-разметку', function (): void {
// 1. Проверка генерации Resource Hints
$hints = SeoHelper::resourceHintsHtml();
assert_same('', $hints, 'локальным шрифтам внешние resource hints не нужны');

// 2. Проверка генерации микроразметки организации
$org = SeoHelper::organizationSchema("https://example.com");
assert_true(str_contains($org, "GovernmentOrganization"));

// 3. Проверка генерации хлебных крошек
$crumbs = SeoHelper::breadcrumbSchema("https://example.com", [
    ["name" => "Главная", "url" => "/"],
    ["name" => "Новости", "url" => "/news"],
]);
assert_true(str_contains($crumbs, "BreadcrumbList"));

// 4. Иконки: ссылка только на существующий файл, манифест — собираемый из настроек
$favs = SeoHelper::faviconsHtml("https://example.com");
assert_contains('href="/manifest.webmanifest"', $favs);
assert_not_contains('site.webmanifest', $favs);
foreach (['/apple-touch-icon.png', '/favicon-32x32.png', '/favicon-16x16.png'] as $icon) {
    $linked = str_contains($favs, 'href="' . $icon . '"');
    assert_same(is_file(dirname(__DIR__, 2) . '/public' . $icon), $linked, "ссылка на {$icon} без файла = 404 на каждой странице");
}
});
