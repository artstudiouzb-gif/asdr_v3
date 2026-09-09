<?php

declare(strict_types=1);

// Демо-главная: фикстура блоков и бандл-изображения должны быть на месте и
// валидны, иначе «Загрузить демо-контент» даст сломанную главную.

test('Демо: фикстура главной валидна и содержит ожидаемые блоки', function () {
    foreach (['ru' => 'home_blocks.json', 'uz' => 'home_blocks_uz.json'] as $lang => $file) {
        $path = APP_ROOT . '/database/demo_assets/' . $file;
        assert_true(is_file($path), "фикстура главной {$lang} существует");

        $blocks = json_decode((string) file_get_contents($path), true);
        assert_true(is_array($blocks) && count($blocks) >= 6, "минимум 6 блоков главной {$lang}");

        $types = array_map(static fn ($b) => $b['type'] ?? '', $blocks);
        foreach (['hero', 'counters', 'cards_grid', 'news_feature', 'media_gallery'] as $need) {
            assert_true(in_array($need, $types, true), "в {$lang} есть блок {$need}");
        }
        assert_true(
            count(array_filter($blocks, static fn (array $block): bool =>
                ($block['type'] ?? '') === 'cards_grid' && ($block['data']['variant'] ?? '') === 'image'
            )) >= 1,
            "в {$lang} есть карточки с изображениями"
        );

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'hero') {
                continue;
            }
            $data = $block['data'] ?? [];
            assert_same(true, $data['overlay_enabled'] ?? null, "у Hero {$lang} явно включено затемнение");
            assert_same('gradient', $data['overlay_mode'] ?? null, "у Hero {$lang} выбран градиент");
            assert_same('auto', $data['overlay_direction'] ?? null, "у Hero {$lang} задано направление");
        }
    }
});

test('Демо: все изображения фикстуры бандлятся в demo_assets', function () {
    $dir = APP_ROOT . '/database/demo_assets';
    $images = [];
    foreach (['home_blocks.json', 'home_blocks_uz.json'] as $fixture) {
        $blocks = json_decode((string) file_get_contents($dir . '/' . $fixture), true);
        array_walk_recursive($blocks, static function ($v, $k) use (&$images) {
            if ($k === 'image' && is_string($v) && $v !== '') {
                $images[] = $v;
            }
        });
    }
    assert_true($images !== [], 'в фикстуре есть изображения');

    foreach (array_unique($images) as $url) {
        $name = basename($url);
        assert_true(is_file($dir . '/' . $name), "изображение $name лежит в demo_assets");
    }
});

test('Демо: данные не содержат inline style и script', function () {
    foreach (['home_blocks.json', 'home_blocks_uz.json', 'prototype_pages.json'] as $fixture) {
        $source = (string) file_get_contents(APP_ROOT . '/database/demo_assets/' . $fixture);
        assert_false(
            preg_match('/<\s*(?:script|style)\b|(?:\s|[\'"])style\s*=|\bon[a-z]+\s*=|javascript\s*:/i', $source) === 1,
            "{$fixture}: найдена исполняемая или inline-разметка"
        );
    }
});

test('Демо: прототипные госстраницы собраны из зарегистрированных блоков', function () {
    $path = APP_ROOT . '/database/demo_assets/prototype_pages.json';
    assert_true(is_file($path), 'файл прототипных страниц существует');
    $pages = json_decode((string) file_get_contents($path), true);
    assert_true(is_array($pages), 'фикстура страниц валидна');

    foreach (['napravleniya', 'ustoychivyy-ekonomicheskiy-rost', 'analitika', 'press-centr', 'meropriyatiya', 'karera', 'kontakty', 'strategiya-2030'] as $slug) {
        assert_true(isset($pages[$slug]['ru']['blocks']), "есть страница $slug");
    }
    foreach ($pages as $slug => $page) {
        foreach (['ru', 'uz'] as $lang) {
            foreach ($page[$lang]['blocks'] ?? [] as $block) {
                $type = (string) ($block[0] ?? '');
                assert_true(\App\Core\BlockRenderer::defaultsFor($type) !== [], "тип блока $type зарегистрирован");
                if ($type !== 'hero') {
                    continue;
                }
                $data = $block[2] ?? [];
                assert_same(true, $data['overlay_enabled'] ?? null, "{$slug}/{$lang}: затемнение Hero включено явно");
                assert_same('gradient', $data['overlay_mode'] ?? null, "{$slug}/{$lang}: режим Hero задан явно");
                assert_same('auto', $data['overlay_direction'] ?? null, "{$slug}/{$lang}: направление Hero задано явно");
            }
        }
    }
});

test('Демо: сравнение стартовой главной не зависит от порядка JSON-ключей', function () {
    $method = new ReflectionMethod(\App\Core\DemoSeeder::class, 'canonicalJsonValue');

    $fromMysql = [
        '_spacing' => 'max',
        'button_text' => 'Последние новости',
        'button_url' => '/news',
        'text' => 'Актуальная информация, документы, новости и услуги в одном месте.',
        'title' => 'Официальный сайт организации',
    ];
    $fixtureOrder = [
        'title' => 'Официальный сайт организации',
        'text' => 'Актуальная информация, документы, новости и услуги в одном месте.',
        'button_text' => 'Последние новости',
        'button_url' => '/news',
        '_spacing' => 'max',
    ];

    assert_same(
        $method->invoke(null, $fixtureOrder),
        $method->invoke(null, $fromMysql),
        'порядок ключей JSON-объекта не влияет на определение нетронутой главной'
    );
    assert_true(
        $method->invoke(null, ['limit' => 3]) !== $method->invoke(null, ['limit' => '3']),
        'типы JSON-значений сравниваются строго'
    );

    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');
    assert_contains('self::canonicalJsonValue($data)', $seeder);
    assert_not_contains('|| $data !== $expected[$i][2])', $seeder);
});

test('Демо: повторный запуск не удаляет страницы и не дополняет настроенное меню', function () {
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');
    assert_not_contains('DELETE FROM pages', $seeder);
    assert_not_contains('DELETE FROM page_translations', $seeder);
    assert_contains("SELECT COUNT(*) FROM menu_items", $seeder);
    assert_contains("prototype_pages.json", $seeder);
    assert_contains("home_blocks_uz.json", $seeder);
    assert_contains("self::seedMenu", $seeder);
    assert_contains("self::verify", $seeder);
    assert_contains('isUntouchedStarterHome', $seeder);

    $cliSeeder = (string) file_get_contents(APP_ROOT . '/database/seed_demo.php');
    assert_contains('Database::isConnected()', $cliSeeder, 'CLI проверяет подключение к БД');
    assert_contains('Database::init($dbConfig)', $cliSeeder, 'CLI самостоятельно инициализирует БД');
});

test('Демо: запуск доступен только в настройках и требует код подтверждения', function () {
    $dashboard = (string) file_get_contents(APP_ROOT . '/app/Views/admin/dashboard.php');
    $settings = (string) file_get_contents(APP_ROOT . '/app/Views/admin/settings/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/SettingsController.php');
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');

    assert_not_contains('/admin/demo-content', $dashboard);
    assert_contains('/admin/settings/demo-content', $settings);
    assert_contains('demo_confirm_code', $settings);
    assert_contains("DEMO_CONFIRM_CODE = 'DEMO'", $controller);
    assert_contains('/admin/settings/demo-content', $routes);
    assert_not_contains("[DashboardController::class, 'seedDemo']", $routes);
    assert_contains('Backup::create()', $controller, 'перед полным сбросом создаётся резервная копия');
});
