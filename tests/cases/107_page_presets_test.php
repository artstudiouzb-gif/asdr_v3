<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Database;
use App\Core\PagePresets;
use App\Models\Block;
use App\Models\BlockSnippet;

test('Сборки страниц: описаны корректно и используют существующие типы блоков', function () {
    $presets = PagePresets::all();
    assert_true(count($presets) >= 5, 'сборок должно быть несколько');

    $known = array_keys(BlockRenderer::defaults());
    foreach ($presets as $id => $preset) {
        assert_true($preset['name'] !== '', "{$id}: нет названия");
        assert_true($preset['description'] !== '', "{$id}: нет описания");
        assert_true($preset['outline'] !== [], "{$id}: нет состава для карточки");
        assert_true($preset['blocks'] !== [], "{$id}: нет блоков");

        foreach ($preset['blocks'] as $block) {
            assert_true(
                in_array($block['type'], $known, true),
                "{$id}: неизвестный тип блока «{$block['type']}» — на сайте вместо секции будет пустой комментарий"
            );
            assert_true(is_array($block['data'] ?? null), "{$id}: у блока {$block['type']} нет данных");
        }
    }
});

test('Сборки страниц: ритм фонов и отступов выдержан', function () {
    foreach (PagePresets::all() as $id => $preset) {
        $backgrounds = [];
        foreach ($preset['blocks'] as $block) {
            $bg = (string) ($block['data']['_bg'] ?? 'none');
            assert_true(in_array($bg, ['none', 'light', 'tint', 'navy'], true), "{$id}: недопустимый фон «{$bg}»");
            // Подложка растягивается только вместе с фоном.
            if ($bg === 'none') {
                assert_false(!empty($block['data']['_fullwidth']), "{$id}: полная ширина без фона бессмысленна");
            }
            $backgrounds[] = $bg;
        }

        // Две подряд одинаковые подложки сливаются в одну секцию.
        for ($i = 1, $n = count($backgrounds); $i < $n; $i++) {
            assert_false(
                $backgrounds[$i] !== 'none' && $backgrounds[$i] === $backgrounds[$i - 1],
                "{$id}: два одинаковых фона подряд ({$backgrounds[$i]})"
            );
        }

        // Тёмная секция — акцент, её не должно быть больше одной.
        $navy = count(array_filter($backgrounds, static fn (string $b): bool => $b === 'navy'));
        assert_true($navy <= 1, "{$id}: тёмных секций больше одной");

        // Первый блок — обложка с максимальным «воздухом» и без анимации:
        // он виден сразу, анимировать его — мигание при загрузке.
        $first = $preset['blocks'][0];
        assert_same('hero', $first['type'], "{$id}: сборка должна начинаться с обложки");
        assert_same('max', (string) ($first['data']['_spacing'] ?? ''), "{$id}: у обложки максимальные отступы");
        assert_false(!empty($first['data']['_reveal']['enabled']), "{$id}: обложку не анимируем");
        assert_same(true, $first['data']['overlay_enabled'] ?? null, "{$id}: затемнение Hero включено явно");
        assert_same('gradient', $first['data']['overlay_mode'] ?? null, "{$id}: режим Hero задан явно");
        assert_same('auto', $first['data']['overlay_direction'] ?? null, "{$id}: направление Hero задано явно");
    }
});

test('Сборки страниц: применяются к странице и рендерятся без ошибок (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("INSERT INTO pages (title, slug, status, created_at) VALUES ('Preset', 'preset-" . bin2hex(random_bytes(3)) . "', 'published', NOW())");
    $pageId = (int) $pdo->lastInsertId();

    foreach (PagePresets::all() as $id => $preset) {
        $count = BlockSnippet::applyToPage($preset['blocks'], $pageId, 'ru', true);
        assert_same(count($preset['blocks']), $count, "{$id}: создано не столько блоков, сколько в сборке");

        $rendered = BlockRenderer::renderPage(Block::forPageLocalized($pageId, 'ru'));
        assert_not_contains('Неизвестный тип блока', $rendered['html'], "{$id}: в HTML попал неизвестный блок");
        assert_true(strlen($rendered['html']) > 500, "{$id}: подозрительно пустой результат");
    }

    // Режим «добавить» не стирает то, что уже было на странице.
    $before = count(Block::forPage($pageId, 'ru'));
    BlockSnippet::applyToPage(PagePresets::find('contacts')['blocks'], $pageId, 'ru', false);
    assert_true(count(Block::forPage($pageId, 'ru')) > $before, 'append обязан добавлять, а не заменять');

    $pdo->exec("DELETE FROM pages WHERE id = {$pageId}");
});

test('Автоматический ритм: те же правила для страниц не из сборки', function () {
    // Демо-контент и импорт собирают страницы своим содержимым, но оформление
    // секций должно подчиняться тем же правилам, что и готовые сборки.
    $pages = [
        ['hero', 'text', 'team_list', 'docs_list', 'contact_cards', 'cta'],
        ['text'],
        ['org_structure', 'text', 'cta'],
        ['hero', 'text', 'counters', 'advantages', 'timeline', 'person_cards', 'faq', 'cta'],
    ];

    foreach ($pages as $types) {
        $looks = PagePresets::rhythmFor($types);
        assert_same(count($types), count($looks), 'оформление нужно каждому блоку');

        $backgrounds = array_map(static fn (array $l): string => (string) $l['_bg'], $looks);
        for ($i = 1, $n = count($backgrounds); $i < $n; $i++) {
            assert_false(
                $backgrounds[$i] !== 'none' && $backgrounds[$i] === $backgrounds[$i - 1],
                'два одинаковых фона подряд: ' . implode(',', $backgrounds)
            );
        }
        assert_true(
            count(array_filter($backgrounds, static fn (string $b): bool => $b === 'navy')) <= 1,
            'тёмных секций больше одной'
        );
        // Первый экран не анимируем.
        assert_false(!empty($looks[0]['_reveal']['enabled']), 'первый блок анимировать нельзя');
        // Подложка во всю ширину — только когда фон есть.
        foreach ($looks as $look) {
            if ($look['_bg'] === 'none') {
                assert_false(!empty($look['_fullwidth']), 'полная ширина без фона');
            }
        }
    }

    assert_same([], PagePresets::rhythmFor([]), 'пустая страница — пустое оформление');
});

test('Демо-контент: оформление берётся из общих правил, тексты остаются своими', function () {
    $seeder = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/DemoSeeder.php');
    assert_contains('PagePresets::rhythmFor', $seeder, 'демо-страницы получают ритм секций');
    // Своё оформление в демо-данных приоритетнее автоматического (+= не перетирает).
    assert_contains('$blockData += $looks', $seeder);
});

test('Ритм не анимирует первый блок и анимирует остальные', function () {
    // Первый блок — первый экран: пряча его до появления, мы задерживаем то,
    // ради чего страницу открыли. То же правило у нового блока в редакторе
    // (BlockPresentationNormalizer::newBlockPresentation), и разъезжаться им
    // нельзя: демо-страницы показывали бы не то, что получает редактор.
    $looks = PagePresets::rhythmFor(['hero', 'counters', 'cards_grid', 'text']);
    assert_false((bool) $looks[0]['_reveal']['enabled'], 'первый блок анимируется');
    for ($i = 1, $n = count($looks); $i < $n; $i++) {
        assert_true((bool) $looks[$i]['_reveal']['enabled'], 'блок ' . $i . ' без появления');
    }
});

test('Демо-главная и демо-проекты тоже получают появление секций', function () {
    // Эти две ветки посева шли мимо ритма, и главная — самая заметная страница
    // демо — показывалась одним снимком: редактор не видел настройки, которая
    // по умолчанию включена.
    $seeder = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/DemoSeeder.php');
    assert_contains('$homeLooks = \\App\\Core\\PagePresets::rhythmFor($homeTypes)', $seeder);
    assert_contains("rhythmFor(['text', \$type])[1]", $seeder);
    // Берём только появление: фоны и отступы у главной и проектов свои.
    assert_contains("\$blockData['_reveal'] = \$homeLooks[\$lookIndex]['_reveal']", $seeder);
    assert_same(2, substr_count($seeder, "array_key_exists('_reveal'"), 'своё появление в данных должно оставаться главнее');
});

test('Сборки страниц: разметка не протекает в поля, которые экранируются', function () {
    ensure_test_db();
    // У части блоков текстовое поле выводится через htmlspecialchars, и <p> из
    // заготовки показывался посетителю как текст. Проверяем итоговый HTML, а не
    // список полей: так тест переживёт добавление новых блоков в сборки.
    foreach (PagePresets::all() as $id => $preset) {
        foreach ($preset['blocks'] as $index => $block) {
            $rendered = BlockRenderer::render([
                'id' => 900 + $index,
                'type' => $block['type'],
                'data' => json_encode($block['data']),
                'custom_css' => '',
            ]);
            assert_false(
                (bool) preg_match('/&lt;\/?[a-z]+[^&]{0,20}&gt;/', $rendered['html']),
                "{$id}/{$block['type']}: HTML-теги выводятся как текст"
            );
        }
    }
});

test('Сборки страниц: ни один блок не рендерится пустым', function () {
    ensure_test_db();
    // Пустая секция на свежей странице читается как поломка вёрстки.
    foreach (PagePresets::all() as $id => $preset) {
        foreach ($preset['blocks'] as $index => $block) {
            $rendered = BlockRenderer::render([
                'id' => 900 + $index,
                'type' => $block['type'],
                'data' => json_encode($block['data']),
                'custom_css' => '',
            ]);
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($rendered['html'])));
            assert_true(
                mb_strlen($text) >= 25,
                "{$id}/{$block['type']}: секция пустая (" . mb_strlen($text) . " симв.)"
            );
        }
    }
});

test('Обложка без фото — заголовочная зона, а не карточка', function () {
    $plain = BlockRenderer::render([
        'id' => 950, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(['title' => 'Заголовок', 'subtitle' => 'Подзаголовок']),
    ]);
    assert_contains('block-hero--plain', $plain['html']);
    assert_not_contains('block-hero--media', $plain['html']);

    // С фотографией и со своим цветом фона оформление прежнее.
    $media = BlockRenderer::render([
        'id' => 951, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(['title' => 'Заголовок', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg']),
    ]);
    assert_contains('block-hero--media', $media['html']);
    assert_not_contains('block-hero--plain', $media['html']);

    $colored = BlockRenderer::render([
        'id' => 952, 'type' => 'hero', 'custom_css' => '',
        'data' => json_encode(['title' => 'Заголовок', 'bg_color' => '#123456']),
    ]);
    assert_contains('block-hero--bgcolor', $colored['html']);
    assert_not_contains('block-hero--plain', $colored['html']);
});

test('Сборки страниц: неизвестный идентификатор не находится', function () {
    assert_same(null, PagePresets::find('нет-такой'));
    assert_same(null, PagePresets::find(''));
    assert_true(PagePresets::find('contacts') !== null);
});
