<?php

declare(strict_types=1);

use App\Core\TranslationGroupHelper;
use App\Core\TranslationGroupMigration;
use App\Models\News;
use App\Models\Project;

test('Мультиязычная архитектура: создание отдельной записи перевода и связывание через translation_group_id', function (): void {
    ensure_test_db();

    TranslationGroupMigration::run();

    $origId = News::create([
        'title' => 'Оригинальная новость (RU)',
        'slug' => 'original-news-ru',
        'excerpt' => 'Лид новости',
        'content' => '<p>Полный контент новости</p>',
        'status' => 'published',
        'lang' => 'ru',
    ]);

    assert_true($origId > 0, 'Новость создана');

    $uzId = TranslationGroupHelper::createTranslation('news', $origId, 'uz');
    assert_true($uzId > 0 && $uzId !== $origId, 'Создан отдельный пост для узбекского языка');

    $uzNews = News::findById($uzId);
    assert_true($uzNews !== null, 'Узбекская новость найдена в таблице news по своему ID');
    assert_same('uz', $uzNews['lang'], 'Узбекская новость имеет lang=uz');
    assert_same($origId, (int) $uzNews['translation_group_id'], 'Узбекская новость привязана к группе перевода оригинала');
    assert_same('original-news-ru', (string) News::findById($origId)['slug'], 'Slug оригинала не меняется при создании перевода');
    assert_true(
        TranslationGroupHelper::isProvisionalNewsSlug((string) $uzNews['slug']),
        'До первого сохранения перевод получает внутренний технический slug'
    );
    assert_not_contains('-uz', (string) $uzNews['slug'], 'Технический slug не строится из slug исходного языка');
    assert_same('', (string) $uzNews['title'], 'Заголовок перевода создаётся пустым');
    assert_same('', (string) ($uzNews['excerpt'] ?? ''), 'Лид исходного языка не копируется');
    assert_same('', (string) ($uzNews['content'] ?? ''), 'Текст исходного языка не копируется');
    assert_same('', (string) ($uzNews['meta_title'] ?? ''), 'SEO-заголовок исходного языка не копируется');
    assert_same('', (string) ($uzNews['meta_description'] ?? ''), 'SEO-описание исходного языка не копируется');

    \App\Core\Database::pdo()
        ->prepare('UPDATE news SET translation_group_id = id WHERE id = :id')
        ->execute([':id' => $uzId]);
    // Автосвязывание — инструмент починки исторических данных: родителя оно
    // ищет по совпадению заголовка или slug. У свежесозданного перевода теперь
    // нет ни того, ни другого (пустой заголовок, технический slug), поэтому
    // восстанавливать связь ему не из чего — и выдумывать родителя оно не
    // должно. Проверяем именно это: чужая группа не назначена, slug не тронут.
    TranslationGroupHelper::autoLinkStandaloneTranslations();
    $relinkedUzNews = News::findById($uzId);
    assert_same($uzId, (int) $relinkedUzNews['translation_group_id'], 'Пустому черновику перевода не назначается чужая группа');
    assert_same((string) $uzNews['slug'], (string) $relinkedUzNews['slug'], 'Автосвязывание сохраняет технический slug перевода');

    // А на данных прежнего формата («Заголовок (UZ)» со slug «…-uz») починка
    // по-прежнему работает — ради этого она и существует. Берём отдельную пару
    // записей, чтобы не добавлять второй узбекский вариант в проверяемую группу.
    $legacyRuId = News::create([
        'title' => 'Историческая новость (RU)',
        'slug' => 'legacy-news-ru',
        'excerpt' => '',
        'content' => '',
        'status' => 'published',
        'lang' => 'ru',
    ]);
    $legacyUzId = News::create([
        'title' => 'Историческая новость (UZ)',
        'slug' => 'legacy-news-ru-uz',
        'excerpt' => '',
        'content' => '',
        'status' => 'draft',
        'lang' => 'uz',
    ]);
    \App\Core\Database::pdo()
        ->prepare('UPDATE news SET translation_group_id = id WHERE id = :id')
        ->execute([':id' => $legacyUzId]);
    TranslationGroupHelper::autoLinkStandaloneTranslations();
    assert_same(
        $legacyRuId,
        (int) News::findById($legacyUzId)['translation_group_id'],
        'Автосвязывание восстановило группу перевода для записи прежнего формата'
    );

    // Возвращаем связь черновика — её проверяют следующие утверждения.
    \App\Core\Database::pdo()
        ->prepare('UPDATE news SET translation_group_id = :gid WHERE id = :id')
        ->execute([':gid' => $origId, ':id' => $uzId]);

    $translations = TranslationGroupHelper::getTranslations('news', $origId);
    assert_true(isset($translations['ru']), 'В группе есть русский вариант');
    assert_true(isset($translations['uz']), 'В группе есть узбекский вариант');
    assert_same($uzId, (int) $translations['uz']['id'], 'Узбекский вариант ссылается на свой отдельный пост');

    assert_false(in_array('uz', News::availableLangs($origId), true), 'Черновик перевода не объявляется на сайте');
    \App\Core\Database::pdo()
        ->prepare("UPDATE news SET status = 'published', published_at = NOW() WHERE id = :id")
        ->execute([':id' => $uzId]);
    assert_true(in_array('uz', News::availableLangs($origId), true), 'Опубликованный перевод доступен от оригинала');
    assert_true(in_array('uz', News::availableLangs($uzId), true), 'Опубликованный перевод доступен от языковой версии');

    News::forceDelete($uzId);
    News::forceDelete($origId);
});

test('Публичные языки проекта учитывают публикацию независимой версии', function (): void {
    ensure_test_db();

    $origId = Project::create([
        'title' => 'Проект RU',
        'slug' => 'translation-project',
        'description' => 'Описание проекта',
        'cover_image' => null,
        'status' => 'published',
        'is_featured' => 0,
        'sort_order' => 0,
    ]);
    $uzId = TranslationGroupHelper::createTranslation('projects', $origId, 'uz');

    assert_false(in_array('uz', Project::availableLangs($origId), true), 'Черновик проекта не объявляется на сайте');
    \App\Core\Database::pdo()
        ->prepare("UPDATE pages SET status = 'published' WHERE id = :id AND entity_type = 'project'")
        ->execute([':id' => $uzId]);
    assert_true(in_array('uz', Project::availableLangs($origId), true), 'Опубликованный перевод доступен от оригинала');
    assert_true(in_array('uz', Project::availableLangs($uzId), true), 'Опубликованный перевод доступен от языковой версии');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/ProjectController.php');
    assert_contains('Project::availableLangs(', $controller, 'публичный контроллер фильтрует черновики');

    Project::forceDelete($uzId);
    Project::forceDelete($origId);
});
