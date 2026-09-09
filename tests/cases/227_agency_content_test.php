<?php

declare(strict_types=1);

use App\Core\BlockTypeRegistry;
use App\Core\HtmlSanitizer;
use App\Core\Icon;
use App\Core\UrlGuard;

// Фикстура реального контента Агентства (database/content/agency_content.php).
// Проверяем то, что нельзя увидеть глазами: совпадение ключей блоков с
// реестром, живые иконки, безопасные ссылки, полноту языков и отсутствие
// демо-остатков.

/** @return array<string, mixed> */
function agency_fixture(): array
{
    /** @var array<string, mixed> $fixture */
    $fixture = require APP_ROOT . '/database/content/agency_content.php';

    return $fixture;
}

/** Все блоки фикстуры: [slug, lang, type, data]. */
function agency_blocks(): array
{
    $blocks = [];
    foreach (agency_fixture()['pages'] as $slug => $langData) {
        foreach ($langData as $lang => $page) {
            foreach ($page['blocks'] as $block) {
                $blocks[] = [$slug, $lang, $block[0], $block[2]];
            }
        }
    }

    return $blocks;
}

test('Контент Агентства: типы блоков зарегистрированы, ключи совпадают с реестром', function () {
    foreach (agency_blocks() as [$slug, $lang, $type, $data]) {
        $where = "{$slug} [{$lang}] → {$type}";
        assert_true(isset(BlockTypeRegistry::defaults()[$type]), 'неизвестный тип блока: ' . $where);
        // Ключ вне DEFAULTS не переживёт первого сохранения блока в админке:
        // collectData собирает данные по белому списку и лишнее отбрасывает.
        // Служебные ключи с подчёркиванием (_reveal, _spacing, _visible_*)
        // хранятся отдельно от полей блока и проверке не подлежат.
        $keys = array_filter(array_keys($data), static fn (string $key): bool => !str_starts_with($key, '_'));
        $unknown = array_diff($keys, array_keys(BlockTypeRegistry::defaults()[$type]));
        assert_same([], array_values($unknown), 'лишние ключи в ' . $where);
    }
});

test('Контент Агентства: иконки существуют в спрайте, ссылки безопасны', function () {
    foreach (agency_blocks() as [$slug, $lang, $type, $data]) {
        foreach ((array) ($data['items'] ?? []) as $item) {
            $icon = (string) ($item['icon_svg'] ?? '');
            if ($icon !== '') {
                assert_same($icon, Icon::cleanName($icon), "иконка «{$icon}» в {$slug} [{$lang}] не проходит нормализацию");
                assert_true(Icon::isAvailable($icon), "иконки «{$icon}» нет в спрайте ({$slug} [{$lang}])");
            }
            $url = (string) ($item['url'] ?? '');
            if ($url !== '') {
                assert_true(UrlGuard::isSafeLink($url), "небезопасная ссылка {$url} в {$slug} [{$lang}]");
            }
        }
    }
});

test('Контент Агентства: разметка текстовых блоков переживает санитайзер', function () {
    foreach (agency_blocks() as [$slug, $lang, $type, $data]) {
        if ($type !== 'text') {
            continue;
        }
        $content = (string) ($data['content'] ?? '');
        if ($content === '') {
            continue;
        }
        $clean = HtmlSanitizer::sanitizeText($content);
        // Списки и выделения должны дожить до страницы: если тег вырезан,
        // на сайте окажется «слипшийся» текст без перечисления.
        foreach (['<p>', '</p>'] as $tag) {
            assert_contains($tag, $clean, "{$tag} вырезан в {$slug} [{$lang}]");
        }
        if (str_contains($content, '<ul>')) {
            assert_contains('<li>', $clean, "список вырезан в {$slug} [{$lang}]");
        }
        if (str_contains($content, '<strong>')) {
            assert_contains('<strong>', $clean, "выделение вырезано в {$slug} [{$lang}]");
        }
    }
});

test('Контент Агентства: правовые акты с реквизитами, у каждой страницы есть русская версия', function () {
    $acts = 0;
    foreach (agency_blocks() as [$slug, $lang, $type, $data]) {
        if ($type !== 'docs_list' || !in_array($data['variant'] ?? '', ['acts', 'acts-editorial'], true)) {
            continue;
        }
        foreach ($data['items'] as $item) {
            $acts++;
            // Карточка акта без номера и даты выглядит как обычный документ —
            // именно по реквизитам акт и ищут.
            assert_true(trim((string) $item['number']) !== '', "акт без номера в {$slug} [{$lang}]");
            assert_true(trim((string) $item['date']) !== '', "акт без даты в {$slug} [{$lang}]");
            assert_true(trim((string) $item['title']) !== '', "акт без названия в {$slug} [{$lang}]");
        }
    }
    assert_true($acts >= 15, 'ожидались акты на трёх языках, найдено записей: ' . $acts);

    foreach (agency_fixture()['pages'] as $slug => $langData) {
        assert_true(isset($langData['ru']), "у страницы {$slug} нет русской версии — она корень группы переводов");
    }
});

test('Контент Агентства: иерархия ссылается на существующие страницы', function () {
    $fixture = agency_fixture();
    foreach ($fixture['hierarchy'] as $child => $parent) {
        assert_true(isset($fixture['pages'][$child]), "иерархия: нет страницы {$child}");
        assert_true(isset($fixture['pages'][$parent]), "иерархия: нет родителя {$parent}");
        assert_true($child !== $parent, 'страница не может быть своим родителем: ' . $child);
    }
});

test('Контент Агентства: демо-данные не просочились', function () {
    $dump = json_encode(agency_fixture(), JSON_UNESCAPED_UNICODE);
    foreach (['Нуриддинов', 'example.uz', 'example.com', 'Примерная', '000-00-00', 'demo-'] as $leftover) {
        assert_not_contains($leftover, (string) $dump, 'демо-данные в фикстуре: ' . $leftover);
    }
});

test('Контент Агентства: все блоки рендерятся и содержимое доходит до HTML', function () {
    // Рендерим стек блоков целиком через renderPage — тем же путём, каким
    // страницу собирает PageController (в том числе распределение h1).
    $renderedPages = [];
    $blockId = 1000;
    foreach (agency_fixture()['pages'] as $slug => $langData) {
        foreach ($langData as $lang => $page) {
            $rows = [];
            foreach ($page['blocks'] as $block) {
                $rows[] = [
                    'id' => $blockId++,
                    'type' => $block[0],
                    'data' => json_encode($block[2], JSON_UNESCAPED_UNICODE),
                    'custom_css' => '',
                ];
            }
            $result = \App\Core\BlockRenderer::renderPage($rows);
            assert_true(isset($result['html']), "страница {$slug} [{$lang}] не отрендерилась");
            $renderedPages[$slug . '|' . $lang] = (string) $result['html'];
        }
    }

    // Ключевые фрагменты должны быть видны на готовой странице, а не только
    // лежать в данных: заголовки разделов, реквизиты актов, строки биографии.
    assert_contains('Чем занимается Агентство', $renderedPages['o-nas|ru']);
    assert_contains('Мониторинг и оценка', $renderedPages['o-nas|ru']);
    assert_contains('Указ Президента № ПФ-6264', $renderedPages['o-nas|ru']);
    assert_contains('act-card', $renderedPages['o-nas|ru']);
    assert_contains('block-text--intro', $renderedPages['o-nas|ru']);
    assert_contains('block-text__principles', $renderedPages['o-nas|ru']);
    assert_contains('block-advantages--indexed', $renderedPages['o-nas|ru']);
    assert_contains('feature-card block-advantages__item', $renderedPages['o-nas|ru']);
    assert_contains('block-text--system', $renderedPages['o-nas|ru']);
    assert_contains('block-stages--history', $renderedPages['o-nas|ru']);
    assert_contains('block-text--spotlight', $renderedPages['o-nas|ru']);
    assert_contains('block-docslist--acts-editorial', $renderedPages['o-nas|ru']);
    assert_contains('Agentlik nima bilan shug‘ullanadi?', $renderedPages['o-nas|uz']);
    assert_contains('What the Agency Does', $renderedPages['o-nas|en']);

    assert_contains('Умурзаков Сардор Уктамович', $renderedPages['direktor|ru']);
    assert_contains('Европейский банк реконструкции и развития', $renderedPages['direktor|ru']);
    assert_contains('Абдукодиров Абдулла Мамасаатович', $renderedPages['pervyy-zamestitel-direktora|ru']);
    assert_contains('Доктор экономических наук', $renderedPages['pervyy-zamestitel-direktora|ru']);

    // Профиль руководителя даёт странице h1 — на этих страницах лид пустой,
    // поэтому второго заголовка первого уровня не появится.
    assert_contains('<h1', $renderedPages['direktor|ru']);
    foreach (agency_fixture()['pages'] as $slug => $langData) {
        foreach ($langData as $lang => $page) {
            $hasProfile = false;
            foreach ($page['blocks'] as $block) {
                $hasProfile = $hasProfile || $block[0] === 'person_profile';
            }
            if ($hasProfile) {
                assert_same('', trim((string) $page['lead']), "у страницы с профилем {$slug} [{$lang}] должен быть пустой лид");
            }
        }
    }
});

test('Контент Агентства: у руководителей есть узбекский перевод', function () {
    $team = agency_fixture()['team'];
    assert_true(count($team) >= 2, 'ожидались директор и первый заместитель');
    foreach ($team as $member) {
        assert_true(trim((string) $member['name']) !== '', 'сотрудник без имени');
        assert_true(trim((string) $member['position']) !== '', 'сотрудник без должности');
        assert_true(isset($member['translations']['uz']['name']), 'нет узбекского имени: ' . $member['name']);
        assert_true(
            trim((string) $member['translations']['uz']['position']) !== '',
            'нет узбекской должности: ' . $member['name']
        );
    }
});
