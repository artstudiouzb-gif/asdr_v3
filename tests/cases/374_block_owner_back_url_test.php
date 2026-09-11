<?php

declare(strict_types=1);

use App\Core\BlockOwner;

/*
 * Возврат из конструктора — в раздел владельца блока.
 *
 * Блок принадлежит строке `pages` и про подтип не знает: конструктор у
 * страницы и у проекта один и тот же партиал. А формы у них разные, и форма
 * страницы на проект отвечает 404 — так разведены разделы админки.
 *
 * Пока адрес возврата писался литералом `/admin/pages/<id>/edit`, кнопка
 * «Назад к странице» в форме блока проекта вела на `/admin/pages/101/edit` и
 * показывала 404 — ровно в тот момент, когда редактор закончил правку. Тем же
 * литералом страдали редиректы после копирования блоков другого языка и после
 * применения шаблона страницы.
 */

test('Адрес возврата считается по подтипу записи', function () {
    $page = ['id' => 7, 'entity_type' => 'page'];
    $project = ['id' => 101, 'entity_type' => 'project'];

    assert_same('/admin/pages/7/edit', BlockOwner::editUrlFor($page));
    assert_same('/admin/projects/101/edit', BlockOwner::editUrlFor($project));
    assert_same('/admin/projects/101/edit?block_lang=uz', BlockOwner::editUrlFor($project, 'uz'));

    // Запись без подтипа — страница: так вели себя все записи до появления
    // проектов, и менять это значило бы уводить их в чужой раздел.
    assert_same('/admin/pages/7/edit', BlockOwner::editUrlFor(['id' => 7]));
    assert_false(BlockOwner::isProject(['id' => 7]), 'без подтипа это страница');
    assert_true(BlockOwner::isProject($project), 'подтип проекта опознан');
});

test('Конструктор и его редиректы не пишут раздел литералом', function () {
    $files = [
        'app/Views/admin/pages/block_form.php',
        'app/Views/admin/pages/_block_editor.php',
        'app/Controllers/Admin/BlockController.php',
        'app/Controllers/Admin/SnippetController.php',
    ];

    foreach ($files as $file) {
        $src = (string) file_get_contents(APP_ROOT . '/' . $file);
        // POST-адреса конструктора общие — они работают с блоками по id
        // записи. Запрещён именно адрес формы: он уводит проект на 404.
        assert_false(
            (bool) preg_match('~/admin/pages/[^\'"]*\{?\$?[^\'"]*/edit~', $src),
            $file . ': адрес формы владельца пишется через BlockOwner'
        );
    }

    $pageController = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PageController.php');
    // Копирование блоков другого языка возвращает туда же, откуда пришли.
    assert_contains('BlockOwner::editUrlFor($owner', $pageController, 'копирование блоков: возврат через BlockOwner');

    $blockController = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    assert_contains(
        "header('Location: /admin/blocks/' . (int) \$block['id'] . '/edit?draft_saved=block%3A'",
        $blockController,
        'после сохранения редактор должен остаться в форме текущего блока'
    );
    assert_not_contains(
        "pageEditUrl(\$block) . '&draft_saved=block%3A'",
        $blockController,
        'сохранение не должно автоматически возвращать к странице или проекту'
    );
});

test('Форма блока подписывает кнопку по владельцу', function () {
    $src = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');

    // Подпись и подсвеченный раздел меню читают тот же признак, что и адрес:
    // «Назад к странице» на блоке проекта обещает не тот раздел.
    assert_contains("'Назад к проекту'", $src, 'у проекта своя подпись кнопки');
    assert_contains("BlockOwner::isProject(", $src, 'подтип спрашивается у BlockOwner');
    assert_contains("\$activeNav = \$ownerIsProject ? 'projects' : 'pages'", $src, 'раздел меню тоже по владельцу');
});
