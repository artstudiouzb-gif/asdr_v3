<?php

declare(strict_types=1);

use App\Models\FileEntry;

// Окно выбора медиа: слева виды файлов со счётчиками, по центру сетка с
// поиском и сортировкой, справа детали выбранного. Раньше это было одно поле
// поиска и плитка на весь диалог, а загрузка пряталась во вторую вкладку.

test('Окно выбора медиа: боковая колонка видов, поиск, сортировка и детали', function (): void {
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/footer.php');

    assert_contains('data-media-nav', $footer, 'боковая колонка видов файлов');
    assert_contains('data-media-sort', $footer, 'выбор порядка файлов');
    assert_contains('data-media-view="grid"', $footer, 'переключатель плитки');
    assert_contains('data-media-view="list"', $footer, 'переключатель списка');
    assert_contains('data-media-details', $footer, 'панель деталей выбранного файла');
    assert_contains('data-media-dropveil', $footer, 'подсказка перетаскивания');
    assert_contains('data-media-selected-drop', $footer, 'снятие выбора из подвала');

    // Значки, которые рисует скрипт, обязаны встретиться в готовом HTML:
    // символы спрайта ищутся по разметке, и без этого узла кнопки колонки
    // выходили бы пустыми.
    assert_contains('data-media-icons', $footer, 'узел, подтягивающий символы спрайта для JS-иконок');
    assert_contains('cloud-upload', $footer);
    assert_contains('player-play', $footer);
});

test('Окно выбора медиа: поиск и сортировка считаются на сервере', function (): void {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    assert_contains("'/admin/media/list?type=' + encodeURIComponent(currentType)", $js);
    assert_contains("'&sort=' + encodeURIComponent(currentSort)", $js);
    assert_contains("'&q=' + encodeURIComponent(query)", $js, 'поиск идёт по всей библиотеке, а не по загруженной порции');
    assert_contains('counts = data.counts || {}', $js, 'счётчики колонки приходят вместе с выдачей');
    assert_contains('renderDetails(', $js);
    assert_contains("event.key === 'ArrowRight'", $js, 'по карточкам можно ходить стрелками');
});

test('Выдача медиабиблиотеки: белый список видов и порядков, счётчики и вес файла', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FileController.php');

    assert_contains('in_array($type, FileEntry::libraryTypes(), true)', $controller);
    assert_contains('in_array($sort, FileEntry::librarySorts(), true)', $controller);
    assert_contains("'counts' => FileEntry::libraryCounts(\$query)", $controller);
    assert_contains("'size' => (int) (\$file['size'] ?? 0)", $controller);
    assert_contains("'created_at' =>", $controller);

    // Виды и порядки объявлены один раз — иначе выдача и счётчики разошлись бы.
    assert_true(in_array('raster', FileEntry::libraryTypes(), true));
    assert_true(in_array('svg', FileEntry::libraryTypes(), true));
    assert_true(in_array('name_asc', FileEntry::librarySorts(), true));
    assert_true(in_array('size_desc', FileEntry::librarySorts(), true));
});

test('Счётчики медиабиблиотеки раскладывают файлы по видам (БД)', function (): void {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec("DELETE FROM files WHERE original_name LIKE 'counttest-%'");

    $rows = [
        ['counttest-a.jpg', 'image/jpeg'],
        ['counttest-b.png', 'image/png'],
        ['counttest-c.svg', 'image/svg+xml'],
        ['counttest-d.mp4', 'video/mp4'],
        ['counttest-e.mp3', 'audio/mpeg'],
        ['counttest-f.pdf', 'application/pdf'],
    ];
    foreach ($rows as $i => [$name, $mime]) {
        $pdo->prepare("INSERT INTO files (original_name, stored_name, mime_type, size, access_type) VALUES (?, ?, ?, ?, 'public')")
            ->execute([$name, 'counttest-' . $i, $mime, 1024]);
    }

    $counts = FileEntry::libraryCounts('counttest-');

    assert_same(6, $counts['all_files']);
    assert_same(5, $counts['all'], 'в «медиа» не входят документы');
    assert_same(3, $counts['image'], 'вектор считается изображением');
    assert_same(2, $counts['raster']);
    assert_same(1, $counts['svg']);
    assert_same(1, $counts['video']);
    assert_same(1, $counts['audio']);
    assert_same(1, $counts['document']);

    $pdo->exec("DELETE FROM files WHERE original_name LIKE 'counttest-%'");
});
