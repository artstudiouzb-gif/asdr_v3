<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Translations;

/**
 * Значок языка в списках страниц, проектов и новостей говорит, видна ли
 * версия на сайте. Прежде черновой перевод выглядел так же, как
 * опубликованный, а сайт на нём отвечал «Перевод пока недоступен».
 */

test('Значок языка: черновик и запланированная версия отмечены, опубликованная — как раньше', function () {
    $html = render_view('app/Views/admin/layout/lang_badges.php', [
        'siteLangs' => ['ru', 'uz', 'qq'],
        'has' => ['ru' => 10, 'uz' => 11, 'qq' => 12],
        'module' => 'news',
        'origId' => 10,
        'visibility' => [10 => 'published', 11 => 'draft', 12 => 'scheduled'],
    ]);
    assert_contains('href="/admin/news/11/edit"', $html);
    assert_true(preg_match('#class="[^"]*lang-badge--draft"[^>]*href="/admin/news/11/edit"#', $html) === 1, 'черновик помечен классом');
    assert_contains('aria-label="UZ: черновик, на сайте не виден"', $html, 'диктор слышит статус');
    assert_contains('lang-badge--scheduled', $html);
    assert_true(preg_match('#class="u-inline-da0ab63f64" href="/admin/news/10/edit"#', $html) === 1, 'опубликованная версия без пометки');

    $legacy = render_view('app/Views/admin/layout/lang_badges.php', ['siteLangs' => ['ru'], 'has' => ['ru' => 5], 'module' => 'pages', 'origId' => 5]);
    assert_not_contains('lang-badge--', $legacy, 'без карты видимости вид прежний');
});

test('Translations::visibility: статус версий страниц и новостей (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $slug = 'vis-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO pages (title, slug, status, lang, created_at) VALUES ('A', :s, 'published', 'ru', NOW())")->execute([':s' => $slug]);
    $pub = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO pages (title, slug, status, lang, translation_group_id, created_at) VALUES ('B', :s, 'draft', 'uz', :g, NOW())")->execute([':s' => $slug . '-uz', ':g' => $pub]);
    $draft = (int) $pdo->lastInsertId();
    assert_same([$pub => 'published', $draft => 'draft'], Translations::visibility('pages', [$pub, $draft, 0]));

    $pdo->prepare("INSERT INTO news (title, slug, status, published_at, lang, created_at) VALUES ('N', :s, 'published', DATE_ADD(NOW(), INTERVAL 2 DAY), 'ru', NOW())")->execute([':s' => $slug]);
    $future = (int) $pdo->lastInsertId();
    assert_same([$future => 'scheduled'], Translations::visibility('news', [$future]));
    assert_same([], Translations::visibility('settings', [1]), 'чужая таблица не спрашивается');
});
