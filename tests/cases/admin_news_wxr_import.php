<?php

declare(strict_types=1);

test('admin WXR importer is routed through the front controller and protected', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/NewsImportController.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $htaccess = (string) file_get_contents($root . '/public/.htaccess');
    $rootHtaccess = (string) file_get_contents($root . '/.htaccess');
    $settings = (string) file_get_contents($root . '/app/Views/admin/settings/index.php');
    $view = (string) file_get_contents($root . '/app/Views/admin/news/import.php');
    $script = (string) file_get_contents($root . '/public/assets/js/admin-news-import.js');

    assert_contains('Auth::requireSuperAdmin()', $controller);
    assert_contains('Csrf::verifyRequest()', $controller);
    assert_contains("\$router->get('/admin/news/import', [\\App\\Controllers\\Admin\\NewsImportController::class, 'index'])", $routes);
    assert_contains("\$router->post('/admin/news/import/upload', [\\App\\Controllers\\Admin\\NewsImportController::class, 'uploadChunk'])", $routes);
    assert_contains("\$router->post('/admin/news/import/inspect', [\\App\\Controllers\\Admin\\NewsImportController::class, 'inspect'])", $routes);
    assert_contains("\$router->post('/admin/news/import/run', [\\App\\Controllers\\Admin\\NewsImportController::class, 'importBatch'])", $routes);
    assert_contains("\$router->post('/admin/news/import/discard', [\\App\\Controllers\\Admin\\NewsImportController::class, 'discard'])", $routes);
    assert_contains('/admin/news/import', $settings);
    assert_contains('data-endpoint="/admin/news/import"', $view);
    assert_contains("endpoint + '/' + encodeURIComponent(action)", $script);
    assert_not_contains('/admin/import-news.php', $settings);
    assert_not_contains('/admin/import-news.php', $view);
    assert_not_contains('/admin/import-news.php', $script);
    assert_not_contains('import-news.php', $htaccess);
    assert_not_contains('admin/import-news\\.php', $rootHtaccess);
    assert_not_contains('shell_exec(', $controller);
    assert_not_contains('exec(', $controller);
});

test('admin WXR importer stages XML outside public and limits upload size', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/NewsImportController.php');

    assert_contains('/storage/imports/wxr', $controller);
    assert_contains('268435456', $controller);
    assert_contains("pathinfo(\$originalName, PATHINFO_EXTENSION)", $controller);
    assert_contains("hash_file('sha256'", $controller);
});

test('admin WXR importer keeps verification separate from writes', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/NewsImportController.php');
    $view = (string) file_get_contents($root . '/app/Views/admin/news/import.php');
    $script = (string) file_get_contents($root . '/public/assets/js/admin-news-import.js');

    assert_contains("'dryRun' => true", $controller);
    assert_contains("'unresolved' => count(\$plan['unresolved'])", $controller);
    assert_contains('Сначала загрузите и проверьте XML.', $controller);
    assert_contains('Черновики — рекомендуется', $view);
    assert_contains('Создать резервную копию перед импортом', $view);
    assert_contains("jsonPost('inspect'", $script);
    assert_contains("jsonPost('run'", $script);
    assert_contains('1024 * 1024', $script);
});

test('WXR-импорт идёт по курсору и бюджету времени, а копия — отдельным шагом', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/NewsImportController.php');
    $importer = (string) file_get_contents($root . '/app/Core/LegacyWxrImporter.php');
    $routes = (string) file_get_contents($root . '/public/index.php');
    $script = (string) file_get_contents($root . '/public/assets/js/admin-news-import.js');
    $legacy = (string) file_get_contents($root . '/app/Core/LegacyCmsImporter.php');

    // Пакет начинается с курсора, а не с начала плана: иначе каждый следующий
    // пакет пролистывал уже перенесённое, спрашивая slugExists на каждую
    // группу, и стоимость пакета росла вместе с его номером.
    assert_contains("\$cursor = \$first ? 0 : (int) (\$state['cursor'] ?? 0)", $controller);
    assert_contains("'offset' => \$cursor,", $controller);
    assert_contains("\$_SESSION[self::SESSION_KEY][\$token]['cursor'] = (int) (\$result['cursor'] ?? 0)", $controller);
    assert_contains("\$offset = max(0, (int) (\$opts['offset'] ?? 0))", $importer);
    assert_contains("\$out['cursor'] = \$index", $importer);

    // Размер пакета задаётся временем: стоимость одной новости непредсказуема,
    // и фиксированное число записей то не выбирало секунды, то не укладывалось
    // в шлюзовой таймаут.
    assert_contains('BATCH_SECONDS', $controller);
    assert_contains("'timeBudget' => (float) self::BATCH_SECONDS", $controller);
    assert_true(
        !str_contains($controller, 'BATCH_SIZE'),
        'фиксированного размера пакета не осталось'
    );
    // Но пакет обязан сдвинуть курсор хотя бы на одну запись, иначе медленная
    // новость останавливает импорт навсегда.
    assert_contains('$processed > 0 && (microtime(true) - $startedAt) >= $budget', $importer);

    // Завершение — по курсору. Пакет, целиком ушедший на уже существующие
    // записи, переносит ноль и при этом до конца плана не дошёл.
    assert_contains("\$done = (int) (\$result['cursor'] ?? 0) >= (int) (\$result['total'] ?? 0)", $controller);

    // Резервная копия — свой запрос: дамп базы съедал весь таймаут первого
    // пакета, и импорт падал до первой перенесённой новости.
    assert_contains('public function backup(): never', $controller);
    assert_contains("\$router->post('/admin/news/import/backup', [\\App\\Controllers\\Admin\\NewsImportController::class, 'backup'])", $routes);
    assert_true(
        !str_contains($controller, 'Импорт не начат: не удалось создать резервную копию'),
        'копия больше не снимается внутри пакета импорта'
    );
    assert_contains("jsonPost('backup'", $script);

    // Обрыв не отменяет импорт: продолжение идёт с курсора.
    assert_contains('data-resume-import', $script);

    // Одна недоступная картинка не должна съедать шлюзовой таймаут пакета.
    assert_contains('private const IMAGE_TIMEOUT = 15;', $legacy);
    assert_contains('self::IMAGE_TIMEOUT', $legacy);
});

test('WXR-импорт: курсор двигает пакеты по плану и не переносит одно дважды (БД)', function (): void {
    ensure_test_db();

    $vendorHost = 'word' . 'press.org';
    $items = '';
    for ($i = 1; $i <= 6; $i++) {
        $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        // Группа перевода у каждой записи своя: здесь проверяется движение
        // курсора по плану, а не связывание переводов WPML.
        $items .= <<<ITEM
  <item>
    <title>Пакетная новость {$i}</title>
    <link>https://asdr.gov.uz/wxr-cursor-{$i}/</link>
    <content:encoded><![CDATA[<p>Тело {$i}</p>]]></content:encoded>
    <wp:post_id>90{$i}</wp:post_id>
    <wp:post_name>wxr-cursor-{$i}</wp:post_name>
    <wp:post_date>2026-03-{$day} 10:00:00</wp:post_date>
    <wp:status>publish</wp:status>
    <wp:post_type>post</wp:post_type>
    <category domain="language" nicename="ru">Russian</category>
    <category domain="post_translations" nicename="pll_cursor{$i}">Translations</category>
  </item>

ITEM;
    }

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:excerpt="http://{$vendorHost}/export/1.2/excerpt/"
    xmlns:wp="http://{$vendorHost}/export/1.2/">
<channel>
  <wp:base_site_url>https://asdr.gov.uz</wp:base_site_url>
{$items}</channel>
</rss>
XML;

    $path = sys_get_temp_dir() . '/wxr-cursor-' . bin2hex(random_bytes(6)) . '.xml';
    file_put_contents($path, $xml);

    $pdo = \App\Core\Database::pdo();
    $cleanup = static function (\PDO $pdo): void {
        $pdo->exec("DELETE FROM news WHERE slug LIKE 'wxr-cursor-%'");
        $pdo->exec("DELETE FROM redirects WHERE from_path LIKE '/wxr-cursor-%'");
    };
    $cleanup($pdo);

    try {
        $opts = [
            'status' => 'draft',
            'langs' => ['ru' => 'ru'],
            'limit' => 2,
            'authorId' => null,
        ];

        $first = \App\Core\LegacyWxrImporter::importFile($path, $opts);
        assert_same(6, $first['total'], 'план целиком виден пакету');
        assert_same(2, $first['imported'], 'первый пакет переносит две записи');
        assert_same(2, $first['cursor'], 'курсор встал после перенесённых');

        // Второй пакет начинается с курсора: без offset он снова уткнулся бы в
        // первые две записи и потратил бы на них по запросу slugExists —
        // именно этот пролистывание с начала и делало импорт квадратичным.
        $second = \App\Core\LegacyWxrImporter::importFile($path, $opts + ['offset' => $first['cursor']]);
        assert_same(2, $second['imported'], 'второй пакет переносит следующие две');
        assert_same(4, $second['cursor'], 'курсор сдвинулся дальше');
        assert_same(0, $second['skipped'], 'уже перенесённое не пролистывается заново');

        $third = \App\Core\LegacyWxrImporter::importFile($path, $opts + ['offset' => $second['cursor']]);
        assert_same(2, $third['imported'], 'третий пакет добирает хвост');
        assert_same(6, $third['cursor'], 'курсор дошёл до конца плана');
        assert_true($third['cursor'] >= $third['total'], 'признак завершения сработал');

        $count = (int) $pdo->query("SELECT COUNT(*) FROM news WHERE slug LIKE 'wxr-cursor-%'")->fetchColumn();
        assert_same(6, $count, 'перенесены все шесть');

        // Защита по slug остаётся на месте и после перехода на курсор: проход
        // с нуля по уже перенесённому плану ничего не создаёт.
        $again = \App\Core\LegacyWxrImporter::importFile($path, $opts);
        assert_same(0, $again['imported'], 'повтор ничего не переносит');
        assert_same(6, $again['cursor'], 'повтор доходит до конца плана, а не встаёт на лимите');
        $count = (int) $pdo->query("SELECT COUNT(*) FROM news WHERE slug LIKE 'wxr-cursor-%'")->fetchColumn();
        assert_same(6, $count, 'дублей не появилось');
    } finally {
        @unlink($path);
        $cleanup($pdo);
    }
});

test('WXR-импорт: бюджет времени обрывает пакет, но всегда двигает курсор (БД)', function (): void {
    ensure_test_db();

    $vendorHost = 'word' . 'press.org';
    $items = '';
    for ($i = 1; $i <= 4; $i++) {
        $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $items .= <<<ITEM
  <item>
    <title>Бюджетная новость {$i}</title>
    <link>https://asdr.gov.uz/wxr-budget-{$i}/</link>
    <content:encoded><![CDATA[<p>Тело {$i}</p>]]></content:encoded>
    <wp:post_id>91{$i}</wp:post_id>
    <wp:post_name>wxr-budget-{$i}</wp:post_name>
    <wp:post_date>2026-04-{$day} 10:00:00</wp:post_date>
    <wp:status>publish</wp:status>
    <wp:post_type>post</wp:post_type>
    <category domain="language" nicename="ru">Russian</category>
    <category domain="post_translations" nicename="pll_budget{$i}">Translations</category>
  </item>

ITEM;
    }

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:excerpt="http://{$vendorHost}/export/1.2/excerpt/"
    xmlns:wp="http://{$vendorHost}/export/1.2/">
<channel>
  <wp:base_site_url>https://asdr.gov.uz</wp:base_site_url>
{$items}</channel>
</rss>
XML;

    $path = sys_get_temp_dir() . '/wxr-budget-' . bin2hex(random_bytes(6)) . '.xml';
    file_put_contents($path, $xml);

    $pdo = \App\Core\Database::pdo();
    $cleanup = static function (\PDO $pdo): void {
        $pdo->exec("DELETE FROM news WHERE slug LIKE 'wxr-budget-%'");
        $pdo->exec("DELETE FROM redirects WHERE from_path LIKE '/wxr-budget-%'");
    };
    $cleanup($pdo);

    try {
        // Заведомо исчерпанный бюджет: любая первая запись уже за ним. Пакет
        // обязан всё равно перенести одну и сдвинуть курсор — иначе импорт
        // встал бы навсегда, каждый раз возвращая «перенесено 0».
        $out = \App\Core\LegacyWxrImporter::importFile($path, [
            'status' => 'draft',
            'langs' => ['ru' => 'ru'],
            'timeBudget' => 0.000001,
            'authorId' => null,
        ]);

        assert_same(4, $out['total'], 'план разобран целиком');
        assert_same(1, $out['imported'], 'ровно одна запись за пакет');
        assert_same(1, $out['cursor'], 'курсор сдвинулся, несмотря на исчерпанный бюджет');
        assert_true($out['cursor'] < $out['total'], 'пакет не считается завершающим');
    } finally {
        @unlink($path);
        $cleanup($pdo);
    }
});
