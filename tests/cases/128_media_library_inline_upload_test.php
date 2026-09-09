<?php

declare(strict_types=1);

test('В медиабиблиотеке можно загрузить новый файл без выхода из формы', function () {
    $root = dirname(__DIR__, 2);
    $footer = (string) file_get_contents($root . '/app/Views/admin/layout/footer.php');
    $js = (string) file_get_contents($root . '/public/assets/js/admin.js');

    assert_contains('data-media-upload-input', $footer);
    assert_contains('data-media-upload-button', $footer);
    assert_contains('data-csrf=', $footer);
    // Загрузка — обычная кнопка панели с нативным полем внутри, а не отдельная
    // вкладка: прежде, чтобы загрузить файл, приходилось уходить из библиотеки.
    assert_contains('data-media-upload-button', $footer);
    assert_not_contains('data-media-tab', $footer, 'вкладки «Библиотека/Загрузка» заменены одной кнопкой');
    assert_contains('data-media-dropveil', $footer, 'файл можно бросить в любое место окна');
    assert_contains("fetch('/admin/files/chunk'", $js);
    assert_contains("fd.append('access_type', 'public')", $js);
    assert_contains('200 * 1024 * 1024', $js);
    assert_contains('loadLibrary(true)', $js);
    assert_contains('selectUrl(res.url)', $js);
    assert_contains("audio: { accept: '.mp3,.aac,.ogg,.wav,.m4a,audio/*'", $js, 'медиабиблиотека знает аудиоформаты');
    assert_contains('pickMany: function (cb)', $js, 'галерея умеет выбирать несколько существующих файлов');
});

test('Галерея новости принимает фото и с компьютера, и из медиабиблиотеки', function () {
    $root = dirname(__DIR__, 2);
    $form = (string) file_get_contents($root . '/app/Views/admin/news/form.php');
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/NewsController.php');

    assert_contains('data-media-gallery-pick', $form);
    assert_contains('name="news_gallery[]"', $form);
    assert_contains("\$_POST['gallery_library']", $controller);
    assert_contains('UrlGuard::isSafeMedia($libraryPath)', $controller);
});

test('После чанковой загрузки сервер возвращает URL нового публичного файла', function () {
    $controller = (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Controllers/Admin/ChunkedUploadController.php'
    );

    assert_contains('use App\\Models\\FileEntry;', $controller);
    assert_contains("'url' => \$accessType === 'public' ? FileEntry::publicUrl(\$file) : null", $controller);
    assert_contains("'mime_type' =>", $controller);
});

test('Стили инспектора файлов не скрывают общую медиабиблиотеку в формах', function () {
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/admin.css');

    assert_same(
        1,
        substr_count($css, "\n.media-modal {"),
        'глобальный контейнер медиабиблиотеки должен иметь только одно определение'
    );
    assert_contains('#media_modal {', $css);
    assert_contains('#media_modal .media-modal__dialog', $css);
    assert_contains('#media_modal .media-modal__close', $css);
});
