<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\ContentEntry;
use App\Models\ContentType;
use App\Models\Project;

test('Проекты: публичные списки и блоки не смешивают независимые языковые версии', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $prefix = 'strict-project-' . bin2hex(random_bytes(4));

    // Языковая версия проекта — отдельная запись pages того же типа, связанная
    // через translation_group_id. Другого механизма у проектов нет.
    $insert = $pdo->prepare(
        "INSERT INTO pages
            (title, slug, entity_type, `lead`, status, is_featured, lang, translation_group_id)
         VALUES (?, ?, 'project', ?, ?, 1, ?, ?)"
    );
    $linkGroup = $pdo->prepare('UPDATE pages SET translation_group_id = ? WHERE id = ?');

    $insert->execute(['RU independent', $prefix . '-independent', 'RU body', 'published', 'ru', null]);
    $independentBase = (int) $pdo->lastInsertId();
    $linkGroup->execute([$independentBase, $independentBase]);
    $insert->execute(['UZ independent', $prefix . '-independent', 'UZ body', 'published', 'uz', $independentBase]);
    $independentUz = (int) $pdo->lastInsertId();

    // Русский проект без узбекской версии в узбекский список не попадает.
    $insert->execute(['RU only', $prefix . '-ru-only', 'RU only body', 'published', 'ru', null]);
    $ruOnly = (int) $pdo->lastInsertId();
    $linkGroup->execute([$ruOnly, $ruOnly]);

    // Черновик языковой версии не публикуется и не делает язык доступным.
    $insert->execute(['RU shadowed', $prefix . '-shadowed', 'Shadowed RU body', 'published', 'ru', null]);
    $shadowedBase = (int) $pdo->lastInsertId();
    $linkGroup->execute([$shadowedBase, $shadowedBase]);
    $insert->execute(['UZ draft', $prefix . '-shadowed', 'Draft body', 'draft', 'uz', $shadowedBase]);
    $shadowDraft = (int) $pdo->lastInsertId();

    $uzRows = Project::published('uz');
    $uzById = [];
    foreach ($uzRows as $row) {
        $uzById[(int) $row['id']] = $row;
    }
    assert_same('UZ independent', $uzById[$independentUz]['title']);
    assert_same('UZ body', $uzById[$independentUz]['description'], 'анонс отдаётся под именем description');
    assert_true(!isset($uzById[$independentBase]), 'RU-версия независимого проекта не дублируется');
    assert_true(!isset($uzById[$ruOnly]), 'проект без узбекской версии в узбекский список не попадает');
    assert_true(!isset($uzById[$shadowDraft]), 'черновик не публикуется');

    $homeIds = array_map(static fn (array $row): int => (int) $row['id'], Project::forHome(24, 'uz'));
    assert_true(in_array($independentUz, $homeIds, true), 'языковая выборка работает и для проектного блока');
    assert_true(!in_array($independentBase, $homeIds, true), 'проектный блок не дублирует RU-версию');

    $found = Project::findPublishedBySlug($prefix . '-independent', 'uz');
    assert_same($independentUz, (int) ($found['id'] ?? 0));
    // Узбекской версии нет — посетителю показываем русскую, а не 404.
    $fallback = Project::findPublishedBySlug($prefix . '-ru-only', 'uz');
    assert_same($ruOnly, (int) ($fallback['id'] ?? 0));

    assert_true(in_array('uz', Project::availableLangs($independentBase), true));
    assert_true(!in_array('uz', Project::availableLangs($shadowedBase), true), 'черновик не делает язык доступным');

    $ids = implode(',', [$independentBase, $independentUz, $ruOnly, $shadowedBase, $shadowDraft]);
    $pdo->exec("DELETE FROM pages WHERE id IN ({$ids})");
});

test('Документы и вакансии: список, поиск, сортировка и счётчик используют текущий язык', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $suffix = bin2hex(random_bytes(4));
    $typeId = ContentType::create('strict-catalog-' . $suffix, 'Строгий каталог', true);
    ContentType::replaceFields($typeId, [[
        'name' => 'department',
        'label' => 'Отдел',
        'field_type' => 'text',
        'required' => false,
        'options' => [],
    ]]);

    $first = ContentEntry::create(
        $typeId,
        'Zeta RU',
        'zeta-' . $suffix,
        'published',
        ['department' => 'Русский отдел']
    );
    $second = ContentEntry::create(
        $typeId,
        'Alpha RU',
        'alpha-' . $suffix,
        'published',
        ['department' => 'Русский сектор']
    );
    $withoutTranslation = ContentEntry::create(
        $typeId,
        'Only RU',
        'only-ru-' . $suffix,
        'published',
        ['department' => 'Только русский']
    );
    ContentEntry::upsertTranslation($first, 'uz', 'Alfa UZ', ['department' => 'Raqamli bo‘lim']);
    ContentEntry::upsertTranslation($second, 'uz', 'Zebra UZ', ['department' => 'Moliya bo‘limi']);

    assert_same(3, ContentEntry::countTypePublic($typeId, '', 'ru'));
    assert_same(2, ContentEntry::countTypePublic($typeId, '', 'uz'));
    assert_same(1, ContentEntry::countTypePublic($typeId, 'Raqamli', 'uz'));

    $uzRows = ContentEntry::forTypePublic($typeId, '', 'title', 12, 0, 'uz');
    assert_same([$first, $second], array_map(static fn (array $row): int => (int) $row['id'], $uzRows));
    assert_same('Alfa UZ', $uzRows[0]['title']);
    $firstData = json_decode((string) $uzRows[0]['data'], true);
    assert_same('Raqamli bo‘lim', $firstData['department'] ?? null);
    assert_true(
        !in_array($withoutTranslation, array_column($uzRows, 'id'), false),
        'запись без перевода не попадает в UZ-каталог'
    );

    $searchRows = ContentEntry::forTypePublic($typeId, 'Moliya', 'new', 12, 0, 'uz');
    assert_same([$second], array_map(static fn (array $row): int => (int) $row['id'], $searchRows));
    assert_same(['ru', 'uz'], ContentEntry::availableLangs($first));
    assert_same(['ru'], ContentEntry::availableLangs($withoutTranslation));

    $pdo->prepare('DELETE FROM content_types WHERE id = ?')->execute([$typeId]);
});

test('Публичные шаблоны каталога переводят названия типов и подписи полей', function () {
    $root = dirname(__DIR__, 2);
    $index = (string) file_get_contents($root . '/app/Views/site/content_index.php');
    $list = (string) file_get_contents($root . '/app/Views/site/_catalog_list.php');
    $show = (string) file_get_contents($root . '/app/Views/site/content_show.php');

    assert_contains("t((string) \$type['name'])", $index);
    assert_contains("t((string) \$type['description'])", $index);
    assert_contains("t((string) \$f['label'])", $list);
    assert_contains("t((string) \$f['label'])", $show);
});
