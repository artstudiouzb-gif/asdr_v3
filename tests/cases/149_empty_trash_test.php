<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\News;
use App\Models\Project;
use App\Controllers\Admin\TrashController;

test('Корзина: очистка всех удалённых элементов (emptyAll) удаляет страницы, новости и проекты', function (): void {
    ensure_test_db();

    $uid = uniqid();

    // Создаём и мягко удаляем страницу
    $pageId = Page::create([
        'title' => 'Мусорная страница',
        'slug' => 'trash-page-test-' . $uid,
        'meta_title' => null,
        'meta_description' => null,
        'status' => 'draft',
        'lang' => 'ru',
    ]);
    Page::delete($pageId);

    // Создаём и мягко удаляем новость
    $newsId = News::create([
        'title' => 'Мусорная новость',
        'slug' => 'trash-news-test-' . $uid,
        'excerpt' => 'Описание',
        'content' => 'Контент',
        'status' => 'draft',
        'lang' => 'ru',
    ]);
    News::delete($newsId);

    // Создаём и мягко удаляем проект
    $projId = Project::create([
        'title' => 'Мусорный проект',
        'slug' => 'trash-project-test-' . $uid,
        'description' => 'Описание',
        'cover_image' => '',
        'status' => 'draft',
        'lang' => 'ru',
    ]);
    Project::delete($projId);

    // Проверяем, что элементы есть в корзине
    assert_true(count(Page::trashed()) > 0, 'В корзине есть страницы');
    assert_true(count(News::trashed()) > 0, 'В корзине есть новости');
    assert_true(count(Project::trashed()) > 0, 'В корзине есть проекты');

    // Вызываем очистку напрямую, минуя HTTP-обвязку действия.
    //
    // Раньше тест звал emptyAll() и рассчитывал перехватить редирект через
    // try/catch. Перехватить его нельзя: действие начинается с
    // Auth::requireLogin(), а тот при неаутентифицированной сессии выполняет
    // header() + exit. exit не является исключением, catch не срабатывал, и
    // завершался весь процесс раннера — вместе с ним молча пропадали все
    // тесты, зарегистрированные после этого файла.
    $controller = new TrashController();
    $removed = $controller->purgeAll();

    assert_true($removed['pages'] >= 1, 'Очистка отчиталась об удалённых страницах');
    assert_true($removed['news'] >= 1, 'Очистка отчиталась об удалённых новостях');
    assert_true($removed['projects'] >= 1, 'Очистка отчиталась об удалённых проектах');

    // Проверяем, что корзина пуста
    assert_same(0, count(Page::trashed()), 'Корзина страниц очищена');
    assert_same(0, count(News::trashed()), 'Корзина новостей очищена');
    assert_same(0, count(Project::trashed()), 'Корзина проектов очищена');
});
