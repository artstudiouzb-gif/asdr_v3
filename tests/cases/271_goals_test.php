<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\WidgetRenderer;
use App\Models\Goal;
use App\Models\GoalTranslation;

/*
 * «Цели» (Maqsadlar) — название, описание и набор снимков. На сайте цель видна
 * каруселью в виджете: название и описание над кадрами. Записей сотни, поэтому
 * у списка админки есть поиск и постраничность, а у виджета — источник
 * «случайная цель» вместо собственного набора кадров.
 *
 * Текст виден посетителю, а значит переводится (механизм А): русское название
 * на узбекской странице — это дефект, а не мелочь.
 *
 * Главная тонкость — кэш: страница кэшируется общим ключом, и цель, выбранная
 * при её сборке, была бы одной и той же для всех посетителей до сброса кэша.
 * Поэтому свежую цель отдаёт отдельный некэшируемый адрес /goals/random.
 */

test('Цель: снимки переписываются целиком, опасный адрес не сохраняется (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM goals');

    $id = Goal::create('Транспорт', 'Дороги и мосты', true);
    Goal::replaceImages($id, [
        ['image' => '/uploads/public/a.jpg', 'alt' => 'Первый кадр'],
        ['image' => 'javascript:alert(1)', 'alt' => 'Опасный'],
        ['image' => '', 'alt' => 'Удалённая строка формы'],
        ['image' => '/uploads/public/b.jpg', 'alt' => ''],
    ]);

    $images = Goal::images($id);
    assert_same(2, count($images), 'опасный адрес и пустая строка не должны сохраняться');
    assert_same('/uploads/public/a.jpg', $images[0]['image']);
    assert_same(0, (int) $images[0]['sort_order'], 'порядок пересчитывается подряд, без дыр');
    assert_same(1, (int) $images[1]['sort_order']);

    // Повторное сохранение заменяет набор, а не дописывает его.
    Goal::replaceImages($id, [['image' => '/uploads/public/c.jpg', 'alt' => '']]);
    assert_same(1, count(Goal::images($id)), 'снимки должны переписываться целиком');

    // Снимки уходят вместе с целью: строки без владельца никому не видны.
    Goal::delete($id);
    $left = Database::pdo()->prepare('SELECT COUNT(*) FROM goal_images WHERE goal_id = ?');
    $left->execute([$id]);
    assert_same(0, (int) $left->fetchColumn(), 'снимки удалённой цели остались в базе');
});

test('Случайная цель: только включённые и только со снимками (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM goals');

    $withImages = Goal::create('Со снимками', '', true);
    Goal::replaceImages($withImages, [['image' => '/uploads/public/a.jpg', 'alt' => '']]);

    // Пустая карусель хуже, чем соседняя цель, поэтому цель без снимков
    // пропускается; выключенная не выбирается вовсе.
    Goal::create('Без снимков', '', true);
    $off = Goal::create('Выключенная', '', false);
    Goal::replaceImages($off, [['image' => '/uploads/public/b.jpg', 'alt' => '']]);

    for ($i = 0; $i < 12; $i++) {
        $random = Goal::random();
        assert_true($random !== null, 'случайная цель не нашлась');
        assert_same('Со снимками', (string) $random['goal']['name'], 'выбрана цель, которой не должно быть в выборке');
    }

    // Ни одной годной цели — виджет обязан честно ответить «нет», а не упасть.
    Database::pdo()->exec('DELETE FROM goals');
    assert_same(null, Goal::random());
});

test('Список админки: поиск и постраничность считают одно и то же (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM goals');

    for ($i = 1; $i <= 25; $i++) {
        $id = Goal::create($i % 5 === 0 ? "Транспорт {$i}" : "Цель {$i}", '', true);
        Goal::replaceImages($id, [['image' => '/uploads/public/a.jpg', 'alt' => '']]);
    }

    assert_same(25, Goal::countAll(''));
    assert_same(5, Goal::countAll('Транспорт'), 'счётчик обязан учитывать поиск');
    assert_same(5, count(Goal::page(20, 0, 'Транспорт')), 'страница обязана учитывать поиск');

    assert_same(20, count(Goal::page(20, 0, '')));
    assert_same(5, count(Goal::page(20, 20, '')), 'вторая страница списка');

    // Число снимков приезжает вместе со строкой: отдельный запрос на каждую
    // цель дал бы N+1 на странице из полусотни записей.
    $rows = Goal::page(5, 0, '');
    assert_true(array_key_exists('image_count', $rows[0]), 'в списке нет числа снимков');
    assert_same(1, (int) $rows[0]['image_count']);
});

test('Виджет: источник «случайная цель» отдаёт кадры цели и просит свежую (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM goals');
    $id = Goal::create('Единственная', 'Что показано на кадрах', true);
    Goal::replaceImages($id, [
        ['image' => '/uploads/public/one.jpg', 'alt' => 'Кадр один'],
        ['image' => '/uploads/public/two.jpg', 'alt' => 'Кадр два'],
    ]);

    $html = WidgetRenderer::render([
        'id' => 5,
        'type' => 'photo_slider',
        'title' => '',
        'data' => json_encode(['source' => 'goals', 'shuffle' => true], JSON_UNESCAPED_UNICODE),
    ], 'ru');

    assert_contains('/uploads/public/one.jpg', $html, 'кадры цели не доехали до виджета');
    assert_contains('data-goal-slider', $html, 'без признака скрипт не запросит свежую цель');

    // Набор снимков без единого слова не сообщает, что за объект показан.
    assert_contains('Единственная', $html, 'название цели не доехало до виджета');
    assert_contains('Что показано на кадрах', $html, 'описание цели не доехало до виджета');
    assert_contains('data-goal-text', $html, 'скрипту нечего подменять вместе с кадрами');
    // Подпись карусели — имя цели, иначе диктор объявляет безликий «Слайдер».
    assert_contains('aria-label="Единственная"', $html, 'карусель не подписана названием цели');

    // Порядок кадров внутри цели авторский: случайной бывает сама цель, а не
    // история, которую рассказывают её слайды.
    assert_true(
        strpos($html, 'data-slider-shuffle') === false,
        'кадры внутри цели перемешиваться не должны, даже когда галочка включена'
    );

});

test('Цель: название и описание переводятся, пустой перевод откатывается (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM goals');

    $id = Goal::create('Транспортная инфраструктура', 'Дороги, мосты и развязки', true);
    Goal::replaceImages($id, [['image' => '/uploads/public/a.jpg', 'alt' => '']]);
    GoalTranslation::upsert($id, 'uz', ['name' => 'Transport infratuzilmasi', 'description' => '']);

    $uz = Goal::find($id, 'uz');
    assert_true($uz !== null);
    assert_same('Transport infratuzilmasi', (string) $uz['name'], 'перевод названия не наложился');
    // Недописанный перевод оставляет текст, а не дыру.
    assert_same('Дороги, мосты и развязки', (string) $uz['description'], 'пустое поле перевода обязано откатываться');

    // Язык не указан — базовая строка как есть, без лишнего запроса за переводом.
    assert_same('Транспортная инфраструктура', (string) Goal::find($id)['name']);

    // Колонка «Языки» в списке админки: основной язык есть всегда, узбекский —
    // потому что название переведено.
    $langs = Goal::availableLangsForIds([$id]);
    assert_true(in_array('uz', $langs[$id], true), 'заполненный перевод не отмечен в списке');
});

test('Виджет: цель приезжает на языке страницы (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM goals');
    $id = Goal::create('Экология', 'Русское описание', true);
    Goal::replaceImages($id, [['image' => '/uploads/public/a.jpg', 'alt' => '']]);
    GoalTranslation::upsert($id, 'uz', ['name' => 'Ekologiya', 'description' => 'Oʻzbekcha tavsif']);

    $html = WidgetRenderer::render([
        'id' => 7,
        'type' => 'photo_slider',
        'title' => '',
        'data' => json_encode(['source' => 'goals'], JSON_UNESCAPED_UNICODE),
    ], 'uz');

    assert_contains('Ekologiya', $html, 'на узбекской странице показано русское название');
    assert_contains('Oʻzbekcha tavsif', $html, 'на узбекской странице показано русское описание');

    // Свежую цель просит скрипт, а про текущий язык он ничего не знает —
    // адрес обязан приехать из разметки уже с языком, иначе после подмены
    // на узбекской странице оказывалась русская цель.
    assert_contains('data-goal-slider="/uz/goals/random"', $html, 'адрес свежей цели потерял язык страницы');
});

test('Виджет со своими снимками текста цели не выводит (БД)', function () {
    ensure_test_db();

    $html = WidgetRenderer::render([
        'id' => 8,
        'type' => 'photo_slider',
        'title' => '',
        'data' => json_encode([
            'source' => 'manual',
            'slides' => [['image' => '/uploads/public/own.jpg', 'alt' => '']],
            'goal_name' => 'Чужое название',
        ], JSON_UNESCAPED_UNICODE),
    ], 'ru');

    assert_contains('/uploads/public/own.jpg', $html);
    // Название принадлежит цели: у собственной витрины кадров его быть неоткуда.
    assert_true(strpos($html, 'Чужое название') === false, 'у своих снимков текста цели быть не должно');
    assert_true(strpos($html, 'data-goal-slider') === false, 'свои снимки не подменяются случайной целью');
});

test('Случайность переживает кэш: цель выбирается на своём адресе, а не при сборке', function () {
    // Цель, выбранная при сборке страницы, уехала бы в кэш и стала общей для
    // всех посетителей — «случайной» она была бы ровно один раз.
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/goals/random'", $routes, 'нет адреса, отдающего свежую цель');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/GoalController.php');
    assert_contains('no-store', $controller, 'ответ со случайной целью обязан быть некэшируемым');
    assert_contains('X-Robots-Tag', $controller, 'служебный фрагмент не должен попадать в индекс');
    // Текст и кадры кладутся в разные места разметки, поэтому едут раздельно.
    assert_contains("'text' =>", $controller, 'в ответе нет текста цели');
    assert_contains("'slides' =>", $controller, 'в ответе нет кадров цели');
    assert_contains('Locale::current()', $controller, 'свежая цель обязана приходить на языке страницы');

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/slider.js');
    assert_contains('/goals/random', $js, 'скрипт не запрашивает свежую цель');
    assert_contains('data-goal-slider', $js, 'скрипт не узнаёт карусель целей');
    // Подменив кадры без текста, мы подписали бы новые снимки прежним именем.
    assert_contains('data-goal-text', $js, 'скрипт не подменяет текст цели вместе с кадрами');
    assert_contains("getAttribute('data-goal-slider')", $js, 'скрипт не читает адрес с языком из разметки');

    // Публичной страницы у цели нет: адреса вида /goals/{slug} быть не должно.
    assert_true(
        strpos($routes, "'/goals/{") === false,
        'у цели появился публичный адрес — её содержимое задумано только для виджета'
    );
});

test('Раздел «Цели» подключён в админке целиком', function () {
    // Раздел без строки в карте иконок молча остаётся без значка, а без пункта
    // меню до него нельзя дойти — оба случая тихие.
    assert_true(\App\Core\AdminUi::navigationIcon('goals') !== '', 'у раздела нет иконки');

    $header = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    assert_contains("'goals' => ['/admin/goals'", $header, 'раздела нет в меню админки');

    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    foreach (['/admin/goals', '/admin/goals/create', '/admin/goals/{id}/edit', '/admin/goals/{id}/delete'] as $route) {
        assert_contains("'" . $route . "'", $routes, 'нет маршрута ' . $route);
    }

    foreach (['index', 'form'] as $view) {
        assert_true(
            is_file(APP_ROOT . '/app/Views/admin/goals/' . $view . '.php'),
            'нет вьюхи админки: ' . $view
        );
    }
});
