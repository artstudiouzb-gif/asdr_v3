<?php

declare(strict_types=1);

test('Создание независимого перевода использует POST, CSRF и атомарное копирование', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $helper = (string) file_get_contents(APP_ROOT . '/app/Core/TranslationGroupHelper.php');
    $badges = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/lang_badges.php');

    foreach (['news', 'pages', 'projects'] as $module) {
        assert_contains(
            "\$router->post('/admin/{$module}/{id}/create-translation'",
            $routes,
            "{$module}: изменение выполняется только через POST"
        );
        assert_not_contains(
            "\$router->get('/admin/{$module}/{id}/create-translation'",
            $routes,
            "{$module}: GET-маршрут создания удалён"
        );
    }

    foreach ([
        APP_ROOT . '/app/Controllers/Admin/NewsController.php',
        APP_ROOT . '/app/Controllers/Admin/PageController.php',
        APP_ROOT . '/app/Controllers/Admin/ProjectController.php',
    ] as $controllerFile) {
        $controller = (string) file_get_contents($controllerFile);
        assert_contains('Csrf::verifyRequest();', $controller, basename($controllerFile) . ': CSRF проверяется');
        assert_contains("\$_POST['target_lang']", $controller, basename($controllerFile) . ': язык читается из POST');
    }

    assert_contains('formmethod="post"', $helper, 'сайдбар переопределяет отправку на POST');
    assert_contains('formnovalidate', $helper, 'кнопку перевода не блокирует валидация текущей формы');
    assert_contains('formaction="/admin/', $helper, 'сайдбар использует отдельный POST endpoint');
    assert_contains('name="target_lang"', $helper, 'сайдбар передаёт целевой язык');
    assert_contains('method="post"', $badges, 'языковой бейдж отправляет POST-форму');
    assert_contains('name="csrf_token"', $badges, 'языковой бейдж передаёт CSRF-токен');

    foreach ([
        APP_ROOT . '/app/Views/admin/news/form.php',
        APP_ROOT . '/app/Views/admin/pages/form.php',
        APP_ROOT . '/app/Views/admin/projects/form.php',
    ] as $formFile) {
        $form = (string) file_get_contents($formFile);
        assert_contains('Csrf::field()', $form, basename(dirname($formFile)) . ': родительская форма содержит CSRF');
        assert_contains('renderSidebarMetaBox', $form, basename(dirname($formFile)) . ': кнопка находится в защищённой форме');
    }

    assert_contains('$pdo->beginTransaction();', $helper, 'создание перевода начинает транзакцию');
    assert_contains('$pdo->commit();', $helper, 'успешное создание фиксирует транзакцию');
    assert_contains('$pdo->rollBack();', $helper, 'ошибка копирования откатывает перевод');
    assert_not_contains('catch (\\Throwable) {}', $helper, 'ошибки копирования блоков не скрываются');
    assert_contains('AND lang = :source_lang', $helper, 'дочерние блоки фильтруются по языку');
    assert_contains('$groupLock->execute', $helper, 'параллельные запросы блокируют общую группу');
});

test('Миграция целостности восстанавливает нулевые корни групп', function (): void {
    $migration = (string) file_get_contents(
        APP_ROOT . '/database/migrations/2026_07_29_translation_group_integrity.sql'
    );

    foreach (['news', 'pages', 'projects'] as $table) {
        assert_contains("UPDATE {$table}", $migration, "{$table}: группа нормализуется");
    }
    assert_contains(
        'translation_group_id IS NULL OR translation_group_id = 0',
        $migration,
        'миграция обрабатывает NULL и 0'
    );

    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    assert_contains(
        '2026_07_29_translation_group_integrity.sql',
        $schema,
        'чистая установка отмечает миграцию применённой'
    );
    // Проекты живут в pages (entity_type='project'), отдельной нормализации им
    // не нужно — их корни выставляет тот же UPDATE по pages.
    foreach (['news', 'pages'] as $table) {
        assert_contains(
            "UPDATE {$table} SET translation_group_id = id",
            $schema,
            "{$table}: чистая схема сразу нормализует группу"
        );
    }
});

test('Ошибка копирования блоков атомарно откатывает создание перевода', function (): void {
    ensure_test_db();

    $pdo = \App\Core\Database::pdo();
    $origId = \App\Models\Page::create([
        'title' => 'Проверка атомарного перевода',
        'slug' => 'atomic-translation-' . bin2hex(random_bytes(3)),
        'status' => 'published',
        'lang' => 'ru',
    ]);
    $trigger = 'test_translation_copy_failure';

    try {
        $insertBlock = $pdo->prepare(
            "INSERT INTO blocks
                (page_id, lang, type, title, data, sort_order, is_active, created_at)
             VALUES (:page_id, 'ru', 'text', 'atomic-failure-marker', '{}', 1, 1, NOW())"
        );
        $insertBlock->execute([':page_id' => $origId]);
        $pdo->prepare('UPDATE pages SET translation_group_id = 0 WHERE id = :id')
            ->execute([':id' => $origId]);

        $pdo->exec("DROP TRIGGER IF EXISTS {$trigger}");
        $pdo->exec(
            "CREATE TRIGGER {$trigger}
             BEFORE INSERT ON blocks
             FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced block copy failure'"
        );

        $failed = false;
        try {
            \App\Core\TranslationGroupHelper::createTranslation('pages', $origId, 'uz');
        } catch (\Throwable) {
            $failed = true;
        }
        assert_true($failed, 'ошибка копирования возвращается вызывающему коду');

        $translatedCount = $pdo->prepare(
            "SELECT COUNT(*)
             FROM pages
             WHERE translation_group_id = :group_id AND lang = 'uz' AND deleted_at IS NULL"
        );
        $translatedCount->execute([':group_id' => $origId]);
        assert_same(0, (int) $translatedCount->fetchColumn(), 'частично созданный перевод откатан');
        assert_same(
            0,
            (int) \App\Models\Page::findById($origId)['translation_group_id'],
            'изменение исходной группы также откатано'
        );
    } finally {
        $pdo->exec("DROP TRIGGER IF EXISTS {$trigger}");
        \App\Models\Page::forceDelete($origId);
    }
});
