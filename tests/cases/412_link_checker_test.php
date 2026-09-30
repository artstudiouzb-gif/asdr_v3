<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\LinkChecker;

/*
 * Проверка ссылок в контенте. Журнал 404 видит битую ссылку только после
 * визита; здесь ссылки собираются из контента, и каждая битая ведёт к записи.
 */

test('ссылки извлекаются из HTML, JSON и значения целиком; служебные схемы пропускаются', function () {
    $html = '<p><a href="/about">О нас</a> <a href="#top">вверх</a> <a href="mailto:a@b.uz">почта</a>'
        . ' <a href="tel:+998">тел</a> <a href="javascript:alert(1)">x</a> <img src="https://cdn.example.org/a.png"></p>';
    $links = LinkChecker::extractLinks($html);
    assert_same(['/about', 'https://cdn.example.org/a.png'], $links);

    $json = (string) json_encode(['url' => '/news?page=2#x', 'content' => '<a href="/contacts">к</a>', 'pattern' => '/^[0-9]+$/']);
    $links = LinkChecker::extractLinks($json);
    assert_true(in_array('/news?page=2', $links, true), 'якорь снимается, строка JSON с экранированным слэшем читается');
    assert_true(in_array('/contacts', $links, true), 'HTML внутри JSON');
    assert_false(in_array('/^[0-9]+$/', $links, true), 'регулярное выражение — не ссылка');

    assert_same(['https://gov.uz/ru'], LinkChecker::extractLinks('https://gov.uz/ru'), 'пункт меню или цель редиректа — значение целиком');
    assert_same([], LinkChecker::extractLinks('about-us'), 'относительный путь без «/» неоднозначен');
});

test('абсолютный адрес своего сайта становится путём, чужой остаётся внешним', function () {
    $own = rtrim((string) Config::get('app.url', ''), '/');
    $host = (string) parse_url($own, PHP_URL_HOST);
    if ($host === '') {
        skip_test('app.url не задан');
    }
    assert_same('/news', LinkChecker::normalize('https://' . $host . '/news#top'));
    assert_same('/news', LinkChecker::normalize('https://www.' . preg_replace('/^www\./', '', $host) . '/news'));
    assert_same('https://example.org/x', LinkChecker::normalize('//example.org/x'));
    assert_true(LinkChecker::isInternal('/news'));
    assert_false(LinkChecker::isInternal('https://example.org/x'));
});

test('битая ссылка на файл и на удалённый защищённый файл ведёт к записи', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM link_checks');
    $missing = '/uploads/public/nope-' . bin2hex(random_bytes(3)) . '.pdf';
    $slug = 'lc-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO news (title, slug, status, content) VALUES (?, ?, 'draft', ?)")
        ->execute(['Приказ ' . $slug, $slug, '<a href="' . $missing . '">приказ</a> <a href="/download.php?file_id=987654321&amp;token=x">док</a>']);
    $newsId = (int) $pdo->lastInsertId();

    assert_same('broken', LinkChecker::check($missing)['state']);
    assert_same('broken', LinkChecker::check('/download.php?file_id=987654321&token=x')['state']);

    $links = LinkChecker::collect();
    assert_true(isset($links[$missing]), 'ссылка из текста новости собрана');

    // Проход без сети: только внутренние ссылки и только файлы — на свой сайт
    // по HTTP тест не ходит, поэтому страницы вроде /about остаются непроверенными.
    $result = LinkChecker::run(false, 60);
    assert_true($result['broken'] >= 2, 'обе ссылки битые: ' . json_encode($result));

    $problems = LinkChecker::problems();
    $row = array_values(array_filter($problems, static fn (array $p): bool => $p['url'] === $missing))[0] ?? null;
    assert_true($row !== null, 'битая ссылка в списке');
    assert_same('/admin/news/' . $newsId . '/edit', $row['places'][0]['url'] ?? '');

    // Ссылку убрали из текста — при следующем проходе она исчезает из списка.
    $pdo->prepare("UPDATE news SET content = '' WHERE id = ?")->execute([$newsId]);
    LinkChecker::run(false, 60);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM link_checks WHERE url = ?');
    $stmt->execute([$missing]);
    assert_same(0, (int) $stmt->fetchColumn());

    $pdo->prepare('DELETE FROM news WHERE id = ?')->execute([$newsId]);
});

test('внешний сервер, не ответивший один раз, ещё не битый', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM link_checks');
    $pdo->prepare("INSERT INTO link_checks (url_hash, url, is_internal, state, fail_streak, checked_at) VALUES (?, ?, 0, 'unreachable', 1, NOW())")
        ->execute([sha1('https://down.example.org/'), 'https://down.example.org/']);
    assert_same(0, LinkChecker::counts()['unreachable']);
    assert_same([], LinkChecker::problems());

    $pdo->exec("UPDATE link_checks SET fail_streak = " . LinkChecker::UNREACHABLE_STREAK);
    assert_same(1, LinkChecker::counts()['unreachable']);
    $pdo->exec('DELETE FROM link_checks');
});

test('проверка ссылок: внешние ходят только через безопасный зонд, а не из веб-запроса', function () {
    $checker = (string) file_get_contents(APP_ROOT . '/app/Core/LinkChecker.php');
    assert_contains('Http::probeSafeRemote(', $checker, 'внешний адрес — только с проверкой публичности хоста');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/LinkController.php');
    assert_contains('LinkChecker::run(false,', $controller, 'кнопка в панели внешние ссылки не проверяет');
});
