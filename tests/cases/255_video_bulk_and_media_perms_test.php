<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Uploader;
use App\Models\FileEntry;
use App\Models\Video;

// Права у файлов медиатеки и массовые действия в разделе «Видео».
//
// Файл из формы приходит через move_uploaded_file() и получает права по umask,
// а файл, который PHP собрал сам (импорт кадров с YouTube, перенос из WP,
// сборка чанковой загрузки), наследует права временного файла — tempnam()
// создаёт его с 0600. Статику отдаёт веб-сервер, и такой файл он прочитать не
// может: картинка выходит битой, хотя запись в медиатеке есть.

test('Uploader: файл, собранный самим PHP, получает права 0644 (нужна тестовая БД)', function () {
    if (!Database::isConnected()) {
        skip_test('нет тестовой БД');
    }
    if (!extension_loaded('gd')) {
        skip_test('нет расширения gd');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'permtest_');
    assert_true($tmp !== false, 'временный файл создан');
    $image = imagecreatetruecolor(4, 4);
    imagejpeg($image, $tmp, 80);
    unset($image);
    chmod($tmp, 0600); // ровно так его отдаёт tempnam()
    $named = $tmp . '.jpg';
    rename($tmp, $named);
    assert_same(0600, fileperms($named) & 0777, 'исходный временный файл закрыт для всех, кроме владельца');

    $file = Uploader::storeFromPath($named, 'perm-test.jpg', (int) filesize($named), 'public', null, false);
    $path = rtrim((string) Config::get('paths.public_uploads'), '/') . '/' . $file['stored_name'];

    assert_true(is_file($path), 'файл сохранён в медиатеку');
    assert_same(0644, fileperms($path) & 0777, 'сохранённый файл читается веб-сервером');

    @unlink($path);
    foreach (glob(preg_replace('/\.[^.]+$/', '', $path) . '*.webp') ?: [] as $variant) {
        @unlink($variant);
    }
    FileEntry::delete((int) $file['id']);
});

test('Видео: массовые публикация, «на главной» и удаление (нужна тестовая БД)', function () {
    if (!Database::isConnected()) {
        skip_test('нет тестовой БД');
    }
    Database::pdo()->exec('DELETE FROM videos');

    $ids = [];
    foreach (['Первое видео', 'Второе видео', 'Третье видео'] as $title) {
        $id = Video::create($title);
        assert_true($id !== null, 'видео создано');
        $ids[] = (int) $id;
    }

    // Импорт с канала заводит черновики — массовая публикация и есть смысл
    // раздела: открывать полсотни записей по одной никто не станет.
    Video::setPublishedMany($ids, false);
    assert_same(2, Video::setPublishedMany([$ids[0], $ids[1]], true), 'опубликованы обе выбранные записи');
    // Повторное применение того же действия: MySQL не считает строки, у которых
    // значение и так было нужным, поэтому счёт идёт по выбранным записям —
    // иначе редактор увидел бы «Опубликовано: 0» на успешной операции.
    assert_same(2, Video::setPublishedMany([$ids[0], $ids[1]], true), 'счёт по выбранным, а не по изменённым строкам');

    $rows = [];
    foreach (Video::all() as $row) {
        $rows[(int) $row['id']] = $row;
    }
    assert_same(1, (int) $rows[$ids[0]]['is_published']);
    assert_same(1, (int) $rows[$ids[1]]['is_published']);
    assert_same(0, (int) $rows[$ids[2]]['is_published'], 'невыбранное видео не тронуто');

    Video::setFeaturedMany([$ids[2]], true);
    assert_same(1, (int) Video::findById($ids[2])['is_featured'], 'отметка «на главной» ставится массово');
    Video::setFeaturedMany([$ids[2]], false);
    assert_same(0, (int) Video::findById($ids[2])['is_featured']);

    // Мусор во входных данных не должен превращаться в удаление чего попало.
    assert_same(0, Video::deleteMany(['0', '-5', 'abc']), 'некорректные id отбрасываются');
    assert_same(3, count(Video::all()), 'ни одна запись не удалена');

    assert_same(2, Video::deleteMany([$ids[0], $ids[1]]), 'удалены обе выбранные записи');
    $left = Video::all();
    assert_same(1, count($left));
    assert_same($ids[2], (int) $left[0]['id']);

    Video::deleteMany([$ids[2]]);
});

test('Список видео: панель массовых действий и подтверждение удаления', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/videos/index.php');
    assert_contains('action="/admin/videos/bulk"', $view, 'форма массовых действий ведёт на свой маршрут');
    assert_contains('data-bulk-form', $view, 'подключена общая логика выбора строк');
    assert_contains('data-select-all', $view, 'есть «выбрать все»');
    assert_contains('data-bulk-item', $view, 'у строк есть флажки');
    foreach (['publish', 'unpublish', 'feature', 'unfeature', 'delete'] as $action) {
        assert_contains('value="' . $action . '"', $view, 'действие ' . $action . ' доступно в списке');
    }

    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("post('/admin/videos/bulk'", $routes, 'маршрут массовых действий зарегистрирован');

    // Удаление видео необратимо (корзины у раздела нет), поэтому массовое
    // удаление обязано спрашивать подтверждение.
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains("action.value === 'delete'", $js, 'массовое удаление подтверждается');
});
