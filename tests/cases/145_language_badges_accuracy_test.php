<?php

declare(strict_types=1);

use App\Models\News;
use App\Models\Page;
use App\Models\Project;
use App\Models\PhotoAlbum;
use App\Models\TeamMember;
use App\Models\Video;
use App\Core\Database;

test('Language Badges Accuracy: зеленый индикатор выводится ТОЛЬКО при наличии непустого перевода', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();

    // 1. Новость без перевода на узбекский
    $stmt = $pdo->prepare("INSERT INTO news (title, slug, status, lang, published_at) VALUES ('Новость RU', 'news-badge-ru-only', 'published', 'ru', NOW())");
    $stmt->execute();
    $newsRuId = (int) $pdo->lastInsertId();

    $newsMap = News::availableLangsForIds([$newsRuId], true);
    assert_true(isset($newsMap[$newsRuId]['ru']), 'Есть RU для новости без перевода');
    assert_false(isset($newsMap[$newsRuId]['uz']), 'НЕТ UZ для новости без перевода');

    // 2. Страница без перевода на узбекский
    $stmt = $pdo->prepare("INSERT INTO pages (title, slug, status, lang) VALUES ('Страница RU', 'page-badge-ru-only', 'published', 'ru')");
    $stmt->execute();
    $pageRuId = (int) $pdo->lastInsertId();

    $pageMap = Page::availableLangsForIds([$pageRuId], true);
    assert_true(isset($pageMap[$pageRuId]['ru']), 'Есть RU для страницы без перевода');
    assert_false(isset($pageMap[$pageRuId]['uz']), 'НЕТ UZ для страницы без перевода');

    // 3. Проект без перевода на узбекский
    $stmt = $pdo->prepare("INSERT INTO pages (title, slug, entity_type, status, lang) VALUES ('Проект RU', 'project-badge-ru-only', 'project', 'published', 'ru')");
    $stmt->execute();
    $projRuId = (int) $pdo->lastInsertId();

    $projMap = Project::availableLangsForIds([$projRuId], true);
    assert_true(isset($projMap[$projRuId]['ru']), 'Есть RU для проекта без перевода');
    assert_false(isset($projMap[$projRuId]['uz']), 'НЕТ UZ для проекта без перевода');

    // Очистка
    $pdo->exec("DELETE FROM news WHERE id = {$newsRuId}");
    $pdo->exec("DELETE FROM pages WHERE id = {$pageRuId}");
    $pdo->exec("DELETE FROM pages WHERE id = {$projRuId}");
});
