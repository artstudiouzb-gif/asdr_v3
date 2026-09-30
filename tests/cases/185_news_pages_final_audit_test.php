<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\TranslationGroupHelper;
use App\Models\News;
use App\Models\Page;

test('Страницы сохраняют выбранный сайдбар и проверяют копирование языковых блоков', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PageController.php');

    assert_contains("['no_sidebar', 'left_sidebar', 'right_sidebar']", $controller);
    assert_contains("!Language::isActive(\$fromLang) || !Language::isActive(\$toLang)", $controller);
    assert_contains("\$fromLang === \$toLang", $controller);
    assert_contains("!str_starts_with(\$referer, '//')", $controller);
    assert_contains("resolveBlockLang((string) (\$page['lang']", $controller);
    assert_not_contains("PageTranslation::upsert(\$pageId, \$activeLang", $controller);

    $siteController = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/PageController.php');
    assert_contains("\$slug !== \$canonicalSlug", $siteController);
    assert_contains("Locale::url(\$canonicalSlug, \$lang)", $siteController);
});

test('Расширенные переводы новостей обновляются только через миграцию', function (): void {
    $model = (string) file_get_contents(APP_ROOT . '/app/Models/NewsTranslation.php');
    $helper = (string) file_get_contents(APP_ROOT . '/app/Core/TranslationGroupHelper.php');
    $migration = (string) file_get_contents(
        APP_ROOT . '/database/migrations/2026_07_30_news_translation_details.sql'
    );
    $indexMigration = (string) file_get_contents(
        APP_ROOT . '/database/migrations/2026_07_30_translation_group_indexes.sql'
    );

    assert_not_contains('SHOW COLUMNS', $model, 'модель не должна менять структуру БД в HTTP-запросе');
    assert_not_contains('ALTER TABLE', $model, 'DDL выполняется только миграцией');
    assert_not_contains('ALTER TABLE', $helper, 'помощник переводов не выполняет DDL в HTTP-запросе');
    foreach (['key_points', 'event_meta', 'timeline_json', 'docs', 'poll_question', 'poll_options_json'] as $column) {
        assert_contains("COLUMN_NAME = '{$column}'", $migration, "миграция проверяет {$column}");
        assert_contains("ADD COLUMN {$column}", $migration, "миграция добавляет {$column}");
    }
    foreach (['news', 'pages', 'projects'] as $table) {
        assert_contains("uq_{$table}_slug_lang", $indexMigration, "{$table}: языковая уникальность slug");
        assert_contains("idx_{$table}_lang_group", $indexMigration, "{$table}: индекс языковой группы");
    }
});

test('Новый перевод новости открывается без текста оригинала, а slug строится из нового заголовка', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $slug = 'tr-src-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO news (title, slug, lang, status, excerpt, content, meta_title, meta_description)
        VALUES ('Оригинал', ?, 'ru', 'published', 'Лид', '<p>Текст</p>', 'SEO', 'Описание')")->execute([$slug]);
    $sourceId = (int) $pdo->lastInsertId();

    $uzId = TranslationGroupHelper::createTranslation('news', $sourceId, 'uz');
    $enId = TranslationGroupHelper::createTranslation('news', $sourceId, 'en');
    $stmt = $pdo->prepare('SELECT * FROM news WHERE id = ?');
    $stmt->execute([$uzId]);
    $uz = $stmt->fetch();
    $stmt->execute([$enId]);
    $en = $stmt->fetch();

    // Скопированный текст оригинала выглядел бы готовым переводом, и русская
    // статья ушла бы на /uz под видом узбекской.
    assert_same('', (string) $uz['title'], 'заголовок перевода новости не копируется');
    foreach (['excerpt' => 'лид', 'content' => 'текст', 'meta_title' => 'SEO-заголовок', 'meta_description' => 'SEO-описание'] as $col => $what) {
        assert_same(null, $uz[$col], $what . ' перевода новости не копируется');
    }
    assert_same('uz', (string) $uz['lang']);
    // Черновик не занимает читаемый адрес: технический slug заменяется при
    // первом сохранении адресом из заголовка на языке перевода.
    assert_true(TranslationGroupHelper::isProvisionalNewsSlug((string) $uz['slug']), 'технический slug до первого сохранения');
    assert_false(TranslationGroupHelper::isProvisionalNewsSlug($slug), 'обычный slug техническим не считается');
    assert_true($uz['slug'] !== $en['slug'], 'технические slug нескольких черновиков не конфликтуют');
    assert_same((int) $uz['translation_group_id'], (int) $en['translation_group_id'], 'версии в одной группе');
    assert_same($uzId, TranslationGroupHelper::createTranslation('news', $sourceId, 'uz'), 'повтор возвращает ту же версию');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NewsController.php');
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    assert_contains(
        'TranslationGroupHelper::isProvisionalNewsSlug($existingSlug)',
        $controller,
        'при первом сохранении технический slug игнорируется'
    );
    assert_contains(
        "\$rawSlug = \$slugInput !== '' ? \$slugInput : \$title",
        $controller,
        'пустой slug формируется из заголовка на языке перевода'
    );
    assert_contains(
        'TranslationGroupHelper::isProvisionalNewsSlug($slugValue)',
        $form,
        'технический slug не показывается редактору'
    );
});

test('Новости валидируют медиа, локализуют webhook и сохраняют полную историю', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NewsController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');
    $revision = (string) file_get_contents(APP_ROOT . '/app/Models/ContentRevision.php');

    assert_contains('UrlGuard::isSafeMedia($audioUrl)', $controller);
    assert_contains("DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i'", $controller);
    assert_contains("'url' => \$base . Locale::url(", $controller);
    assert_contains("'lang' => (string) (\$data['lang']", $controller);
    assert_contains('UrlGuard::isSafeMedia($audioUrl)', $view, 'старые небезопасные audio URL не выводятся');
    assert_contains("'sidebar_layout'", $revision);
    assert_contains("'poll_options_json'", $revision);
    assert_contains("'table' => 'news_polls'", $revision);
});

test('Дубликаты страниц и новостей становятся самостоятельными группами перевода', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $slug = 'dup-' . bin2hex(random_bytes(3));

    // Оригинал — версия в чужой группе: копия обязана из неё выйти, иначе
    // у группы оказалось бы две записи одного языка.
    $pdo->prepare("INSERT INTO news (title, slug, lang, status, translation_group_id) VALUES ('Новость', ?, 'uz', 'published', 999999)")
        ->execute([$slug]);
    $newsId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO news_polls (news_id, question, options_json) VALUES (?, 'Вопрос?', '[\"Да\",\"Нет\"]')")
        ->execute([$newsId]);
    $copyId = (int) News::duplicate($newsId);
    $stmt = $pdo->prepare('SELECT slug, lang, status, translation_group_id FROM news WHERE id = ?');
    $stmt->execute([$copyId]);
    $copy = $stmt->fetch();
    assert_same($copyId, (int) $copy['translation_group_id'], 'новость: копия получает собственную группу');
    assert_same('uz', (string) $copy['lang']);
    assert_same('draft', (string) $copy['status']);
    assert_true($copy['slug'] !== $slug, 'новость: slug копии свободен в её языке');
    $polls = $pdo->prepare('SELECT COUNT(*) FROM news_polls WHERE news_id = ?');
    $polls->execute([$copyId]);
    assert_same(1, (int) $polls->fetchColumn(), 'опрос копируется');

    $pdo->prepare("INSERT INTO pages (title, slug, lang, status, translation_group_id) VALUES ('Страница', ?, 'uz', 'published', 999999)")
        ->execute([$slug]);
    $pageId = (int) $pdo->lastInsertId();
    $pageCopyId = (int) Page::duplicate($pageId);
    $stmt = $pdo->prepare('SELECT slug, lang, translation_group_id FROM pages WHERE id = ?');
    $stmt->execute([$pageCopyId]);
    $pageCopy = $stmt->fetch();
    assert_same($pageCopyId, (int) $pageCopy['translation_group_id'], 'страница: копия получает собственную группу');
    assert_true($pageCopy['slug'] !== $slug, 'страница: slug копии свободен в её языке');
});
