<?php

declare(strict_types=1);

use App\Controllers\Admin\BulkController;
use App\Models\Goal;
use App\Models\PhotoAlbum;
use App\Models\TeamMember;

/*
 * Массовые действия у «Целей», «Команды» и «Фотоальбомов» (тесты 278/279
 * держат бюджеты классов и !important — эта задача их не трогает, новых
 * классов и `!important` не заводится).
 *
 * Набор действий объявлен один раз в `BulkController::actions()`, и у каждого
 * типа он свой:
 *  - у News/Page/Project — publish/unpublish/duplicate/trash (без изменений);
 *  - у Goal нет draft/published — только `is_active`, поэтому действия
 *    называются activate/deactivate, а не publish/unpublish: подписывать
 *    чужое состояние своим именем — врать редактору;
 *  - у TeamMember и PhotoAlbum дублирования никогда не было — методов
 *    `duplicate()` у них нет, и заводить фиктивную кнопку незачем;
 *  - удаление у Goal/TeamMember/PhotoAlbum настоящее (`DELETE FROM`, без
 *    `deleted_at`), поэтому подпись — «Удалить», а не «В корзину»: у
 *    последней подписи в проекте есть смысл — можно откатить, здесь нельзя.
 *
 * `BulkController::labels()` — единственный источник опций выпадающего
 * списка: вьюхи не заводят свой список, а читают его отсюда же, откуда его
 * читает и сам обработчик (`handle()`). Второй список в шаблоне разъехался бы
 * с этим молча — тот же случай, что у схемы полей блока.
 */

test('BulkController: у каждого типа свой набор действий', function (): void {
    $news = BulkController::labels('news');
    assert_same(['publish', 'unpublish', 'duplicate', 'trash'], array_keys($news));
    assert_same('В корзину', $news['trash'], 'у новости мягкое удаление — подпись обещает восстановление, и это правда');

    $pages = BulkController::labels('pages');
    assert_same(['publish', 'unpublish', 'duplicate', 'trash'], array_keys($pages));

    $projects = BulkController::labels('projects');
    assert_same(['publish', 'unpublish', 'duplicate', 'trash'], array_keys($projects));

    $team = BulkController::labels('team');
    assert_same(['publish', 'unpublish', 'delete'], array_keys($team));
    assert_same('Удалить', $team['delete'], 'у сотрудника удаление настоящее (DELETE FROM) — «В корзину» было бы неправдой');

    $albums = BulkController::labels('albums');
    assert_same(['publish', 'unpublish', 'delete'], array_keys($albums));
    assert_same('Удалить', $albums['delete']);

    $goals = BulkController::labels('goals');
    // У цели нет статуса draft/published — только is_active («показывать в
    // карусели»), поэтому действия называются иначе, а не «Опубликовать» /
    // «Снять с публикации»: это и есть главное, что стережёт тест.
    assert_same(['activate', 'deactivate', 'delete'], array_keys($goals));
    assert_false(isset($goals['unpublish']), 'у целей нет черновика — снимать с публикации в этом смысле нечего');
    assert_false(isset($goals['publish']), 'у целей нет публикации статьи — только показ в карусели');
    assert_same('Удалить', $goals['delete']);

    // Дублирования нет ни у одного из трёх новых типов — методов duplicate()
    // у их моделей никогда не было.
    assert_false(isset($team['duplicate']), 'дублирования у сотрудников нет');
    assert_false(isset($albums['duplicate']), 'дублирования у альбомов нет');
    assert_false(isset($goals['duplicate']), 'дублирования у целей нет');

    assert_same([], BulkController::labels('unknown'), 'неизвестный тип не должен получать чужой набор действий');
});

test('BulkController: списки трёх разделов спрашивают действия у контроллера, а не заводят свой', function (): void {
    $views = [
        'goals' => 'app/Views/admin/goals/index.php',
        'team' => 'app/Views/admin/team/index.php',
        'albums' => 'app/Views/admin/albums/index.php',
    ];
    foreach ($views as $type => $relative) {
        $path = APP_ROOT . '/' . $relative;
        $src = file_get_contents($path);
        assert_true($src !== false, $path . ' не читается');
        assert_contains("BulkController::labels('{$type}')", (string) $src, $relative . ': опции должны браться из BulkController::labels(), а не хардкодиться');
        // Второй, захардкоженный список опций рядом с первым разъехался бы
        // молча при следующей правке — как со схемой полей блока.
        assert_not_contains('<option value="publish">Опубликовать</option>', (string) $src, $relative . ': опция не должна быть записана в шаблоне буквально');
    }
});

test('Goal::setActive переключает показ в карусели, а не статус; удаление настоящее (БД)', function (): void {
    ensure_test_db();
    $id = Goal::create('Bulk-тест цели', '', true);
    try {
        assert_same(1, (int) Goal::find($id)['is_active']);

        Goal::setActive($id, false);
        assert_same(0, (int) Goal::find($id)['is_active']);

        Goal::setActive($id, true);
        assert_same(1, (int) Goal::find($id)['is_active']);
    } finally {
        Goal::delete($id);
    }
    // Удаление настоящее: строки не остаётся вовсе, никакого deleted_at.
    assert_true(Goal::find($id) === null, 'после Goal::delete() запись не должна находиться');
});

test('TeamMember::setStatus меняет статус точечно, недопустимое значение игнорируется; удаление настоящее (БД)', function (): void {
    ensure_test_db();
    $id = TeamMember::create(['name' => 'Bulk-тест сотрудника', 'status' => 'published']);
    try {
        TeamMember::setStatus($id, 'draft');
        assert_same('draft', TeamMember::findById($id)['status']);

        TeamMember::setStatus($id, 'published');
        assert_same('published', TeamMember::findById($id)['status']);

        // Как у News/Page/Project: значение вне ['draft','published'] не проходит.
        TeamMember::setStatus($id, 'archived');
        assert_same('published', TeamMember::findById($id)['status'], 'недопустимый статус должен быть молча отклонён');
    } finally {
        TeamMember::delete($id);
    }
    assert_true(TeamMember::findById($id) === null, 'после TeamMember::delete() запись не должна находиться');
});

test('PhotoAlbum::setPublished переключает публикацию точечно; удаление настоящее (БД)', function (): void {
    ensure_test_db();
    $id = PhotoAlbum::create('Bulk-тест альбома', '', '', true);
    assert_true($id !== null);
    try {
        PhotoAlbum::setPublished((int) $id, false);
        assert_same(0, (int) PhotoAlbum::findById((int) $id)['is_published']);

        PhotoAlbum::setPublished((int) $id, true);
        assert_same(1, (int) PhotoAlbum::findById((int) $id)['is_published']);
    } finally {
        PhotoAlbum::delete((int) $id);
    }
    assert_true(PhotoAlbum::findById((int) $id) === null, 'после PhotoAlbum::delete() запись не должна находиться');
});
