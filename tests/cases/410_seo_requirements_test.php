<?php

declare(strict_types=1);

use App\Controllers\Site\SitemapController;
use App\Core\SchemaOrg;

test('robots.txt: поиск закрыт на всех языках, соседние адреса — нет', function () {
    $txt = SitemapController::robotsTxt('https://example.uz', ['ru', 'uz'], 'ru');

    assert_contains("Disallow: /search$\n", $txt);
    assert_contains("Disallow: /search?\n", $txt);
    assert_contains("Disallow: /uz/search?\n", $txt, 'выдача поиска на узбекском тоже закрыта');
    assert_contains("Disallow: /admin/\n", $txt);
    assert_not_contains('Disallow: /uz/admin', $txt, 'у панели языкового префикса не бывает');
    // Правило — префикс: голое «/health» закрыло бы и страницу /healthcare.
    assert_not_contains("Disallow: /health\n", $txt);
    assert_contains("Disallow: /health$\n", $txt);
    assert_not_contains('/opendata', $txt, 'открытые данные — публичный раздел');
    assert_contains('Clean-param: utm_source&', $txt);
    assert_contains("Sitemap: https://example.uz/sitemap.xml\n", $txt);
});

test('NewsArticle: даты ташкентским временем, правка не раньше публикации', function () {
    $a = SchemaOrg::newsArticle('Заголовок', 'https://x.uz/news/a', '2026-09-01 12:00:00', '', '', '', '2026-08-30 10:00:00');
    assert_same('2026-09-01T12:00:00+05:00', $a['datePublished']);
    assert_same($a['datePublished'], $a['dateModified'], 'черновик, правленный до выхода, изменён в момент публикации');

    $bad = SchemaOrg::newsArticle('Заголовок', 'https://x.uz/news/a', 'не дата');
    assert_false(isset($bad['datePublished']), 'неразобранная дата не превращается в 1970 год');
    assert_false(isset($bad['dateModified']));
});

test('Новость подписана ведомством, а не «персоной» с его названием', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');
    assert_contains('SeoHelper::organizationRef($base)', $view);
    assert_not_contains("\$news['author']", $view, 'колонки author у новостей нет — был вечный запасной вариант');

    $helper = (string) file_get_contents(APP_ROOT . '/app/Core/SeoHelper.php');
    assert_contains('"publisher" => ["@id" => self::organizationId($appUrl)]', $helper, 'WebSite связан с ведомством');
});

test('<head>: кодировка первой, крупные превью разрешены', function () {
    $head = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $at = strpos($head, '<head>');
    assert_true($at !== false);
    $afterHead = substr($head, (int) $at);
    $charset = strpos($afterHead, '<meta charset="utf-8">');
    assert_true($charset !== false && $charset < (int) strpos($afterHead, '<meta name="viewport"'), 'charset раньше всех meta');
    assert_contains('max-image-preview:large', $head);
});

test('Карта сайта: каждая версия своим <url>, без редиректов, с разделами и картинками (БД)', function () {
    ensure_test_db();
    $pdo = App\Core\Database::pdo();
    foreach (['news_translations', 'news', 'page_translations', 'pages', 'photo_album_translations', 'photo_albums', 'content_entry_translations', 'content_entries'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }

    $pdo->exec("INSERT INTO news (title, slug, status, lang, image, published_at, created_at, updated_at)
                VALUES ('Новость', 'sm-news', 'published', 'ru', '/uploads/public/cover.jpg',
                        '2026-09-01 10:00:00', '2026-09-01 09:00:00', '2026-09-05 08:00:00')");
    $newsId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO news_translations (news_id, lang, title, excerpt, content) VALUES (:id, 'uz', 'Yangilik', '', '')")
        ->execute([':id' => $newsId]);

    $pdo->exec("INSERT INTO pages (title, slug, lang, entity_type, status, section, created_at, updated_at)
                VALUES ('Шапка ленты', 'press', 'ru', 'page', 'published', 'news', NOW(), NOW())");
    $pdo->exec("INSERT INTO pages (title, slug, lang, entity_type, status, created_at, updated_at)
                VALUES ('Контакты', 'kontakty', 'ru', 'page', 'published', NOW(), NOW())");
    $pdo->exec("INSERT INTO photo_albums (title, slug, cover_url, is_published) VALUES ('Альбом', 'sm-album', '/uploads/public/album.webp', 1)");
    $pdo->exec("INSERT INTO content_entries (type_id, title, slug, status, data) VALUES (1, 'Документ', 'sm-doc', 'published', '{}')");

    $xml = SitemapController::sitemapXml('https://example.uz');
    $doc = simplexml_load_string($xml);
    assert_true($doc !== false, 'карта — корректный XML');

    $locs = [];
    foreach ($doc->url as $url) {
        $locs[] = (string) $url->loc;
    }
    assert_same(count($locs), count(array_unique($locs)), 'адрес не повторяется');

    assert_true(in_array('https://example.uz/news/sm-news', $locs, true));
    assert_true(in_array('https://example.uz/uz/news/sm-news', $locs, true), 'перевод полями — свой <url>, а не только alternate');
    assert_false(in_array('https://example.uz/press', $locs, true), 'шапка раздела отвечает 301 — её в карте нет');
    assert_true(in_array('https://example.uz/news', $locs, true), 'вместо неё — сам раздел');
    assert_true(in_array('https://example.uz/kontakty', $locs, true));
    assert_true(in_array('https://example.uz/albums/sm-album', $locs, true));
    assert_false(in_array('https://example.uz/uz/albums/sm-album', $locs, true), 'без перевода альбома узбекской версии нет');
    assert_true(in_array('https://example.uz/catalog/documenty/sm-doc', $locs, true));

    assert_contains('<lastmod>2026-09-05T08:00:00+05:00</lastmod>', $xml, 'lastmod — последняя правка, а не дата публикации');
    assert_contains('<image:loc>https://example.uz/uploads/public/cover.jpg</image:loc>', $xml);
    assert_contains('<image:loc>https://example.uz/uploads/public/album.webp</image:loc>', $xml);
    assert_contains('hreflang="x-default" href="https://example.uz/news/sm-news"', $xml);
});

test('Лента /rss.xml: верное пространство Atom и ссылка на себя (БД)', function () {
    ensure_test_db();
    ob_start();
    (new SitemapController())->rss();
    $rss = (string) ob_get_clean();

    assert_true(simplexml_load_string($rss) !== false, 'лента разбирается');
    assert_contains('xmlns:atom="http://www.w3.org/2005/Atom"', $rss);
    assert_contains('rel="self"', $rss);
    assert_not_contains('image/jpeg" />', $rss);
});

test('IndexNow: адреса берутся из карты сайта по дате правки', function () {
    $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<url><loc>https://example.uz/old</loc><lastmod>2026-09-01T10:00:00+05:00</lastmod></url>'
        . '<url><loc>https://example.uz/new</loc><lastmod>2026-09-10T10:00:00+05:00</lastmod></url>'
        . '<url><loc>https://example.uz/no-date</loc></url>'
        . '</urlset>';
    $since = (int) strtotime('2026-09-05T00:00:00+05:00');

    assert_same(['https://example.uz/new'], App\Core\Seo\IndexNow::changedUrls($xml, $since));
    assert_same([], App\Core\Seo\IndexNow::changedUrls('не xml', $since), 'битая карта — не повод слать мусор');
});

test('IndexNow: уведомление по протоколу и только с публичного https', function () {
    $payload = App\Core\Seo\IndexNow::payload('https://asdr.uz', 'abcdef0123456789', ['https://asdr.uz/news/a']);
    assert_same('asdr.uz', $payload['host']);
    assert_same('https://asdr.uz/indexnow.txt', $payload['keyLocation'], 'ключ в корне — область уведомлений весь сайт');
    assert_same(['https://asdr.uz/news/a'], $payload['urlList']);

    assert_true(App\Core\Seo\IndexNow::isPublicBase('https://asdr.uz'));
    foreach (['http://asdr.uz', 'https://localhost', 'https://127.0.0.1', 'https://asdr.test', ''] as $base) {
        assert_false(App\Core\Seo\IndexNow::isPublicBase($base), $base . ' — не адрес для уведомлений');
    }

    assert_true(App\Core\Seo\IndexNow::isValidKey(bin2hex(random_bytes(16))));
    assert_false(App\Core\Seo\IndexNow::isValidKey('short'));
    assert_false(App\Core\Seo\IndexNow::isValidKey("abc\ndef0123"), 'ключ уходит в файл и тело запроса как есть');
});

test('IndexNow: файл ключа — вне языкового редиректа, отправка — только в воркере', function () {
    assert_false(App\Core\LocalePreference::managesPath('/indexnow.txt'), 'языковой редирект увёл бы проверку ключа на 404');
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/indexnow.txt'", $routes);
    assert_true(isset(App\Core\IntegrationStatus::KNOWN['indexnow']), 'исход виден в «Состоянии системы»');

    // Публичный рендер в сеть не ходит (тест 110): IndexNow зовёт только воркер.
    foreach (['app/Controllers/Site', 'app/Views/site'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            assert_not_contains('IndexNow::run', (string) file_get_contents((string) $file), (string) $file);
        }
    }
});

test('IndexNow без cron: сохранение контента само уведомляет поисковики после ответа', function () {
    // Точка одна — сброс кэша страниц: через неё проходит любое сохранение
    // опубликованного контента.
    $cache = (string) file_get_contents(APP_ROOT . '/app/Core/Cache.php');
    assert_contains('Seo\IndexNow::afterResponse()', $cache);

    // Из консоли (импорт, тесты) наружу не ходим: у воркера свой вызов.
    App\Core\Cache::forgetPrefix('page:');
    $scheduled = (new ReflectionClass(App\Core\Seo\IndexNow::class))->getStaticPropertyValue('scheduled');
    assert_false($scheduled, 'в CLI отправка после ответа не планируется');

    // Правка в ту же секунду, что прошлая отправка, не теряется.
    $xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.uz/a</loc>'
        . '<lastmod>2026-09-05T00:00:00+05:00</lastmod></url></urlset>';
    assert_same(['https://example.uz/a'], App\Core\Seo\IndexNow::changedUrls($xml, (int) strtotime('2026-09-05T00:00:00+05:00')));
});
