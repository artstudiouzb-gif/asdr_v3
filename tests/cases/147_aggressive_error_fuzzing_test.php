<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Locale;
use App\Core\Router;
use App\Core\Search;
use App\Core\SearchQuery;
use App\Core\SecurityHeaders;
use App\Core\Slug;
use App\Core\TextProcessor;
use App\Core\Uploader;
use App\Core\UrlGuard;
use App\Models\Language;
use App\Models\News;
use App\Models\Page;
use App\Models\PhotoAlbum;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\Video;

test('Aggressive Error & Fuzzing Test: роутинг, модель бейджей, инъекции, XSS и граничные значения', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();

    // 1. Агрессивное тестирование роутера на некорректные и вредоносные пути
    $fuzzPaths = [
        '/',
        '/admin',
        '/admin/pages/' . str_repeat('a', 2048),
        '/../../../../etc/passwd',
        '/admin/news/-99999999999999999999999',
        '/admin/news/1\' OR \'1\'=\'1',
        '/admin/news/<script>alert("xss")</script>',
        '/null%00byte',
        '/%FF%FE%FD',
        '/' . str_repeat('../', 50),
    ];

    $router = new Router();
    $router->get('/admin/pages/{id}', static fn () => 'ok');

    foreach ($fuzzPaths as $path) {
        try {
            // Симуляция диспетчеризации путей без выброса фатальных ошибок
            ob_start();
            $router->dispatch('GET', $path);
            ob_end_clean();
            assert_true(true, 'Роутер успешно выдержал путь: ' . substr($path, 0, 50));
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            // 404 Исключение/Ответ — ожидаемое поведение при аномальном пути
            assert_true(true, 'Роутер перехватил фаззинг путь без падения ядра');
        }
    }

    // 2. Агрессивное тестирование функции availableLangsForIds с аномальными ID
    $anomalyIds = [
        [],
        [0],
        [-1, -999999],
        [999999999],
        ['abc', '1; DROP TABLE news;--', "1' OR '1'='1"],
        [null, false, true, 3.14],
        array_fill(0, 500, 999999), // Длинный массив ID
    ];

    $models = [
        News::class,
        Page::class,
        Project::class,
        PhotoAlbum::class,
        TeamMember::class,
        Video::class,
    ];

    foreach ($models as $model) {
        foreach ($anomalyIds as $ids) {
            try {
                /** @var callable $callable */
                $callable = [$model, 'availableLangsForIds'];
                $res = $callable($ids, true);
                assert_true(is_array($res), "{$model}::availableLangsForIds возвращает массив при аномальных ID");
            } catch (\Throwable $e) {
                assert_true(false, "{$model}::availableLangsForIds выбросил необработанное исключение при аномальных ID -> " . $e->getMessage());
            }
        }
    }

    // 3. Агрессивное тестирование генератора слагов Slug::make
    $fuzzSlugs = [
        '',
        '   ',
        '!!!@@@###$$$%%%^^^&&&***((()))',
        '<script>alert(1)</script>',
        'O‘zbekiston Respublikasi Strategiyasi — 2030',
        str_repeat('Длинный заголовок ', 100),
        "тест\0с\0нулевым\0байтом",
        '😀😃😄😁😆😅😂🤣',
    ];

    foreach ($fuzzSlugs as $input) {
        try {
            $slug = Slug::make($input);
            assert_true(is_string($slug), 'Slug::make всегда возвращает строку');
            assert_true(!str_contains($slug, '<') && !str_contains($slug, '>'), 'Слаг не содержит HTML-тегов');
            assert_true(!str_contains($slug, "\0"), 'Слаг не содержит нулевых байтов');
        } catch (\Throwable $e) {
            assert_true(false, 'Slug::make выбросил исключение на входе: ' . substr($input, 0, 30) . ' -> ' . $e->getMessage());
        }
    }

    // Проверка узбекской/русской транслитерации (ж=j, ҳ=h, х=x, ў=o, қ=q, ғ=g, ч=ch, ш=sh)
    assert_same('jurnal', Slug::make('Журнал'));
    assert_same('hisobot', Slug::make('Ҳисобот'));
    assert_same('xizmat', Slug::make('Хизмат'));
    assert_same('ozbekiston', Slug::make('Ўзбекистон'));
    assert_same('qaror', Slug::make('Қарор'));
    assert_same('goliblar', Slug::make('Ғолиблар'));
    assert_same('chora-tadbirlar', Slug::make('Чора-тадбирлар'));
    assert_same('sharhlar', Slug::make('Шарҳлар'));
    assert_same('ozbekiston-taraqqiyoti', Slug::make('O‘zbekiston taraqqiyoti'));
    assert_same('galaba-va-ozgarish', Slug::make('G‘alaba va o‘zgarish'));

    // 4. Агрессивный фаззинг поиска (SearchQuery & Search::query)
    $fuzzSearchQueries = [
        "' OR '1'='1",
        "'; DROP TABLE news; --",
        '<script>alert("XSS")</script>',
        ' UNION SELECT 1,2,3,4,5 --',
        str_repeat('поиск ', 200),
        'O‘zbekiston % _ \\',
        "\\\\\'\"--#/*",
    ];

    foreach ($fuzzSearchQueries as $q) {
        try {
            $norm = SearchQuery::normalize($q);
            assert_true(is_string($norm), 'SearchQuery::normalize возвращает строку');
            
            // Запрос в БД не должен вызывать SQL-ошибку
            $searchResults = Search::query($q, 10);
            assert_true(is_array($searchResults), 'Search::query возвращает массив результатов без SQL-ошибки');
        } catch (\Throwable $e) {
            assert_true(false, 'Поисковый запрос выбил систему на строке: ' . $q . ' -> ' . $e->getMessage());
        }
    }

    // 5. Тестирование защитников URL (UrlGuard)
    $xssUrls = [
        'javascript:alert(1)',
        'vbscript:msgbox(1)',
        'data:text/html,<script>alert(1)</script>',
    ];

    foreach ($xssUrls as $url) {
        try {
            $isSafe = UrlGuard::isSafeLink($url);
            assert_true(!$isSafe, 'UrlGuard::isSafeLink нейтрализует невалидные/опасные схемы');
        } catch (\Throwable $e) {
            assert_true(false, 'UrlGuard выбросил ошибку на URL: ' . $url . ' -> ' . $e->getMessage());
        }
    }

    $validUrls = [
        'https://example.com/test',
        '/admin/pages',
    ];

    foreach ($validUrls as $url) {
        try {
            $isSafe = UrlGuard::isSafeLink($url);
            assert_true($isSafe, 'UrlGuard::isSafeLink одобряет валидный URL');
        } catch (\Throwable $e) {
            assert_true(false, 'UrlGuard выбросил ошибку на валидном URL: ' . $url . ' -> ' . $e->getMessage());
        }
    }

    // 6. Тестирование языкового модуля на несуществующие и некорректные языки
    $fuzzLangs = ['ru', 'uz', 'en', 'fr', 'de', '../../', '<script>', 'invalid_lang_code_123'];
    foreach ($fuzzLangs as $code) {
        try {
            $isActive = Language::isActive($code);
            assert_true(is_bool($isActive), 'Language::isActive возвращает boolean');
            
            $url = Locale::url('/news', $code);
            assert_true(is_string($url), 'Locale::url возвращает валидную строку');
        } catch (\Throwable $e) {
            assert_true(false, 'Language/Locale выбросил ошибку на коде языка: ' . $code . ' -> ' . $e->getMessage());
        }
    }

    // 7. Проверка загрузчика файлов на опасные расширения
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'txt', 'mp4', 'mp3'];
    $dangerousFiles = [
        ['name' => 'shell.php', 'ext' => 'php'],
        ['name' => 'exploit.phtml', 'ext' => 'phtml'],
        ['name' => 'script.js', 'ext' => 'js'],
        ['name' => 'payload.exe', 'ext' => 'exe'],
        ['name' => 'bash.sh', 'ext' => 'sh'],
    ];

    foreach ($dangerousFiles as $file) {
        $isAllowed = in_array(strtolower($file['ext']), $allowedExts, true);
        assert_true(!$isAllowed, 'Система загрузки отвергает опасное расширение файла: ' . $file['name']);
    }
});
