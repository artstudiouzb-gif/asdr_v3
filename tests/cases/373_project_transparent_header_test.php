<?php

declare(strict_types=1);

use App\Models\Project;

/*
 * Прозрачная шапка у проекта.
 *
 * Режим включается двумя условиями сразу (`_header.php`): в конструкторе шапки
 * разрешена прозрачность и у самой записи стоит флаг. Колонка
 * `pages.transparent_header` у проекта была всегда — проект это строка в
 * `pages`, — но взять флагу было неоткуда: в форме проекта его не рисовали,
 * `Project::create/update` колонку не писали, а публичная вьюха не передавала
 * значение в шапку. Любого одного пропуска хватало, чтобы настройка молча
 * ничего не делала.
 */

test('Флаг прозрачной шапки доезжает от формы проекта до записи', function () {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/projects/form.php');
    assert_contains('name="transparent_header"', $form, 'флажок есть в форме проекта');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/ProjectController.php');
    assert_contains("'transparent_header' => !empty(\$_POST['transparent_header'])", $controller, 'контроллер собирает флаг');

    $model = (string) file_get_contents(APP_ROOT . '/app/Models/Project.php');
    // Колонка нужна в обоих запросах: только в UPDATE — и новый проект
    // создавался бы всегда со сплошной шапкой.
    assert_same(2, substr_count($model, "':transparent_header' =>"), 'колонка пишется и при создании, и при правке');
});

test('Публичная вьюха проекта отдаёт флаг шапке до её вывода', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/project_show.php');

    $flag = strpos($view, '$transparentHeader = ');
    $header = strpos($view, "require __DIR__ . '/_header.php'");
    assert_true($flag !== false, 'вьюха выставляет $transparentHeader');
    // Порядок здесь и есть суть: шапка решает про режим один раз, при выводе,
    // и значение, выставленное после неё, уже ничего не меняет.
    assert_true($flag < $header, 'флаг выставлен до подключения шапки');
});

test('Перевод проекта наследует прозрачную шапку', function () {
    $helper = (string) file_get_contents(APP_ROOT . '/app/Core/TranslationGroupHelper.php');

    // Блоки перевода копируются, значит обложка первым блоком у него та же —
    // без флага шапка у языковой версии молча становилась бы сплошной.
    $project = strpos($helper, "VALUES (:t, :s, 'project'");
    assert_true($project !== false, 'ветка перевода проекта на месте');
    assert_contains('transparent_header', substr($helper, $project - 400, 1200), 'флаг копируется в перевод');
});

test('Проект хранит прозрачную шапку (БД)', function () {
    ensure_test_db();

    $uid = uniqid();
    $id = Project::create([
        'title' => 'Проект с прозрачной шапкой',
        'slug' => 'transparent-header-test-' . $uid,
        'description' => null,
        'cover_image' => null,
        'status' => 'draft',
        'transparent_header' => true,
        'lang' => 'ru',
    ]);

    $saved = Project::findById($id);
    assert_true($saved !== null, 'проект создан');
    assert_same(1, (int) ($saved['transparent_header'] ?? 0), 'флаг записан при создании');

    Project::update($id, [
        'title' => 'Проект с прозрачной шапкой',
        'slug' => 'transparent-header-test-' . $uid,
        'description' => null,
        'cover_image' => null,
        'status' => 'draft',
        'transparent_header' => false,
    ]);
    $off = Project::findById($id);
    // Снятая галочка обязана сниматься: галочка, которую нельзя выключить,
    // хуже её отсутствия.
    assert_same(0, (int) ($off['transparent_header'] ?? 1), 'флаг снимается при правке');

    Project::delete($id);
});
