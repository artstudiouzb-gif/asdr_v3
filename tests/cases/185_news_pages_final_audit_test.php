<?php

declare(strict_types=1);

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
    $helper = (string) file_get_contents(APP_ROOT . '/app/Core/TranslationGroupHelper.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NewsController.php');
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');

    assert_contains(
        'NEWS_TRANSLATION_DRAFT_SLUG_PREFIX',
        $helper,
        'новая языковая версия получает отдельный технический slug до первого сохранения'
    );
    assert_contains('bin2hex(random_bytes(6))', $helper, 'технические slug нескольких черновиков не конфликтуют');
    assert_contains("':t' => ''", $helper, 'заголовок перевода новости не копируется');
    assert_contains("':e' => null", $helper, 'лид перевода новости не копируется');
    assert_contains("':c' => null", $helper, 'текст перевода новости не копируется');
    assert_contains("':mt' => null", $helper, 'SEO-заголовок перевода новости не копируется');
    assert_contains("':md' => null", $helper, 'SEO-описание перевода новости не копируется');
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
    foreach (['Page.php', 'News.php'] as $file) {
        $model = (string) file_get_contents(APP_ROOT . '/app/Models/' . $file);
        assert_contains("'translation_group_id' => null", $model, "{$file}: копия отделяется от исходной группы");
        assert_contains('SET translation_group_id = id', $model, "{$file}: копия получает собственную группу");
        assert_contains('self::slugExists($s, null, $lang)', $model, "{$file}: slug проверяется в языке копии");
    }

    $news = (string) file_get_contents(APP_ROOT . '/app/Models/News.php');
    assert_contains("copyChildren('news_polls'", $news, 'опрос копируется без голосов');
});
