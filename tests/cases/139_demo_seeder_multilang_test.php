<?php

declare(strict_types=1);

use App\Core\DemoSeeder;
use App\Core\TranslationGroupHelper;
use App\Models\News;
use App\Models\NewsTranslation;

test('DemoSeeder создает демо-данные с многоязычными деталями (Тезисы, Мероприятие, Документы, Опрос)', function (): void {
    ensure_test_db();

    $pdo = \App\Core\Database::pdo();
    DemoSeeder::run($pdo);

    $news = News::findPublishedBySlug('zasedanie-strategiya-2030');
    assert_true($news !== null, 'Флагманская демо-новость создана');

    $uzTrans = NewsTranslation::find((int) $news['id'], 'uz');
    assert_true($uzTrans !== null, 'Узбекский перевод для демо-новости создан');
    assert_contains('O‘zbekiston–2030', (string) $uzTrans['title'], 'Узбекский заголовок новости создан');
    // Рубрика демо-новости — категория с переводом названия, а не текст в
    // бейдже: свежая установка должна получать готовый справочник рубрик.
    $demoCategoryId = (int) ($news['category_id'] ?? 0);
    assert_true($demoCategoryId > 0, 'У флагманской демо-новости задана категория');
    assert_same(
        'Tadbirlar',
        (string) (\App\Models\NewsCategory::namesForIds([$demoCategoryId], 'uz')[$demoCategoryId] ?? ''),
        'Узбекское название рубрики создано'
    );
    assert_contains('ustuvor yo‘nalishlari', (string) $uzTrans['key_points'], 'Узбекские тезисы новости созданы');
    assert_true(in_array('uz', News::availableLangs((int) $news['id']), true), 'Опубликованная UZ-версия доступна на сайте');

    assert_contains('#Узбекистан2030', (string) $news['hashtags'], 'У флагманской новости заполнены хештеги');
    $timeline = json_decode((string) $news['timeline_json'], true);
    assert_true(is_array($timeline) && count($timeline) >= 3, 'У флагманской новости заполнена хроника');

    $pollCountStmt = $pdo->prepare('SELECT COUNT(*) FROM news_polls WHERE news_id = :news_id');
    $pollCountStmt->execute([':news_id' => (int) $news['id']]);
    assert_same(1, (int) $pollCountStmt->fetchColumn(), 'У флагманской новости создан опрос');

    $homePages = $pdo->query(
        "SELECT lang, id, translation_group_id
         FROM pages
         WHERE slug = 'home' AND lang IN ('ru', 'uz') AND deleted_at IS NULL"
    )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    assert_true(isset($homePages['ru'], $homePages['uz']), 'Главная создана отдельными RU- и UZ-страницами');
    assert_same(
        (int) $homePages['ru']['id'],
        (int) $homePages['ru']['translation_group_id'],
        'RU-главная является корнем группы переводов'
    );
    assert_same(
        (int) $homePages['ru']['id'],
        (int) $homePages['uz']['translation_group_id'],
        'UZ-главная связана с RU-главной'
    );

    $uzHomeBlocksStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM blocks
         WHERE page_id = :page_id AND lang = 'uz'"
    );
    $uzHomeBlocksStmt->execute([':page_id' => (int) $homePages['uz']['id']]);
    $uzHomeBlocks = (int) $uzHomeBlocksStmt->fetchColumn();
    assert_true($uzHomeBlocks >= 6, 'Главная страница имеет полный UZ-стек блоков');

    $demoPagesStmt = $pdo->prepare(
        "SELECT lang, id, translation_group_id
         FROM pages
         WHERE slug = 'o-nas' AND lang IN ('ru', 'uz') AND deleted_at IS NULL"
    );
    $demoPagesStmt->execute();
    $demoPages = $demoPagesStmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    assert_true(isset($demoPages['ru'], $demoPages['uz']), 'Демо-раздел создан отдельными RU- и UZ-страницами');
    assert_true(
        (int) $demoPages['ru']['id'] !== (int) $demoPages['uz']['id'],
        'Языковые версии имеют разные page_id'
    );
    assert_same(
        (int) $demoPages['ru']['id'],
        (int) $demoPages['uz']['translation_group_id'],
        'Языковые версии входят в одну группу переводов'
    );

    $translations = TranslationGroupHelper::getTranslations('pages', (int) $demoPages['uz']['id']);
    assert_true(isset($translations['ru'], $translations['uz']), 'Редактор видит оба существующих перевода');
    assert_same(
        (int) $demoPages['uz']['id'],
        (int) $translations['uz']['id'],
        'Кнопка UZ в редакторе ведёт на существующую страницу'
    );

    $mixedStacksStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM blocks
         WHERE (page_id = :ru_id AND lang <> \'ru\')
            OR (page_id = :uz_id AND lang <> \'uz\')'
    );
    $mixedStacksStmt->execute([
        ':ru_id' => (int) $demoPages['ru']['id'],
        ':uz_id' => (int) $demoPages['uz']['id'],
    ]);
    assert_same(0, (int) $mixedStacksStmt->fetchColumn(), 'Блоки языков не смешиваются между страницами');

    foreach (['content_entry_translations', 'photo_album_translations', 'video_translations', 'team_member_translations'] as $table) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE lang = 'uz'")->fetchColumn();
        assert_true($count > 0, "{$table}: созданы узбекские переводы");
    }

    // У проекта языковая версия — отдельная запись своего языка (так её и
    // заводит редактор), а не строка в таблице переводов.
    $uzProjects = (int) $pdo->query(
        "SELECT COUNT(*) FROM pages WHERE entity_type = 'project' AND lang = 'uz' AND deleted_at IS NULL"
    )->fetchColumn();
    assert_true($uzProjects > 0, 'проекты: созданы узбекские записи');
    $uzProjectBlocks = (int) $pdo->query(
        "SELECT COUNT(*) FROM blocks b
         INNER JOIN pages p ON p.id = b.page_id
         WHERE p.entity_type = 'project' AND p.lang = 'uz' AND b.lang = 'uz'"
    )->fetchColumn();
    assert_true($uzProjectBlocks > 0, 'проекты: у узбекской записи свой стек блоков');
});

test('DemoSeeder: повторный запуск не плодит пустые рубрики (БД)', function (): void {
    ensure_test_db();

    $pdo = \App\Core\Database::pdo();
    DemoSeeder::run($pdo);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM news_categories')->fetchColumn();

    // Второй запуск: новости уже есть и пропускаются (NOT EXISTS). Рубрики к
    // ним заводить нельзя — они повиснут без единой записи. Раньше так и было:
    // рядом с «Мероприятие» появлялись «Мероприятия» и «Карьера» с нулём новостей.
    DemoSeeder::run($pdo);

    assert_same($before, (int) $pdo->query('SELECT COUNT(*) FROM news_categories')->fetchColumn(), 'новых рубрик не добавилось');

    // Проверяем только рубрики демо-комплекта: в общей тестовой базе живут
    // ещё и рубрики соседних тестов, и они к DemoSeeder отношения не имеют.
    $demoSlugs = "'meropriyatiya','cifrovizaciya','regionalnoe-razvitie','analitika','zelenaya-ekonomika','karera'";
    assert_same(
        0,
        (int) $pdo->query(
            "SELECT COUNT(*) FROM news_categories c
             WHERE c.slug IN ({$demoSlugs})
               AND NOT EXISTS (SELECT 1 FROM news n WHERE n.category_id = c.id AND n.deleted_at IS NULL)"
        )->fetchColumn(),
        'демо-рубрик без новостей быть не должно'
    );
});

test('DemoSeeder: старой демо-новости проставляется рубрика, дубль-бейдж снимается (БД)', function (): void {
    ensure_test_db();

    $pdo = \App\Core\Database::pdo();
    DemoSeeder::run($pdo);

    // Приводим базу к виду установки, которая старше категорий: рубрика лежит
    // текстом в бейдже, категории нет.
    $pdo->exec('DELETE FROM news_categories');
    $pdo->exec('UPDATE news SET category_id = NULL, badge = NULL');
    $pdo->prepare("UPDATE news SET badge = 'Цифровизация' WHERE slug = :slug AND lang = 'ru'")
        ->execute([':slug' => 'platforma-monitoringa-reform']);

    DemoSeeder::run($pdo);

    $row = $pdo->query(
        "SELECT category_id, badge FROM news WHERE slug = 'platforma-monitoringa-reform' AND lang = 'ru' LIMIT 1"
    )->fetch();

    assert_true((int) ($row['category_id'] ?? 0) > 0, 'рубрика проставлена существующей новости');
    // Иначе карточка показала бы рубрику дважды: категорией и меткой.
    assert_same(null, $row['badge'], 'старый бейдж-рубрика снят');
});
