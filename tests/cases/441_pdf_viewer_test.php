<?php

declare(strict_types=1);

use App\Controllers\Site\PdfController;

/*
 * Просмотр PDF на телефонах (/pdf): Chrome на Android своего просмотрщика не
 * имеет и скачивает файл, поэтому ссылку на PDF ведёт сюда frontend.js.
 * Страница открывает только свой PDF из загрузок — иначе под нашим доменом
 * показывался бы любой файл из интернета.
 */

test('Просмотр PDF принимает только свой файл из загрузок', function (): void {
    $dir = APP_ROOT . '/public/uploads/public/_pdf_viewer_test';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/Отчёт 2026.pdf', '%PDF-1.4');
    file_put_contents($dir . '/rules.docx', 'x');
    try {
        $ok = '/uploads/public/_pdf_viewer_test/' . rawurlencode('Отчёт 2026.pdf');
        assert_same($ok, PdfController::filePath($ok), 'путь из ссылки (закодированный) принимается');
        assert_same(
            '/uploads/public/_pdf_viewer_test/Отчёт 2026.pdf',
            PdfController::filePath('/uploads/public/_pdf_viewer_test/Отчёт 2026.pdf')
        );
        assert_same(null, PdfController::filePath('/uploads/public/_pdf_viewer_test/missing.pdf'), 'файла нет');
        assert_same(null, PdfController::filePath('/uploads/public/_pdf_viewer_test/rules.docx'), 'не PDF');
        assert_same(null, PdfController::filePath('https://example.com/uploads/public/x.pdf'), 'чужой адрес');
        assert_same(null, PdfController::filePath('/uploads/public/_pdf_viewer_test/../../../config/x.pdf'), 'выход из каталога');
        assert_same(null, PdfController::filePath('/uploads/public/_pdf_viewer_test/%2E%2E/%2E%2E/x.pdf'), 'закодированный выход');
        assert_same(null, PdfController::filePath('/uploads/public/_pdf_viewer_test/x.pdf?y=1'), 'параметры адреса');
    } finally {
        @unlink($dir . '/Отчёт 2026.pdf');
        @unlink($dir . '/rules.docx');
        @rmdir($dir);
    }
});

test('Подвал передаёт скрипту язык для просмотрщика PDF', function (): void {
    $footer = render_view('app/Views/site/_footer.php', []);
    // Язык идёт параметром, а префикс адреса ставит сервер: начало адреса
    // перехода у скрипта постоянное, из разметки в него ничего не попадает.
    assert_contains('"pdfLang":', $footer);
});

test('Просмотр PDF переводит параметр языка в префикс адреса', function (): void {
    assert_same('/uz/pdf?file=%2Fuploads%2Fpublic%2Fa.pdf', PdfController::languageTarget('uz', '/uploads/public/a.pdf', 'ru', ['ru', 'uz']));
    assert_same(null, PdfController::languageTarget('ru', '/uploads/public/a.pdf', 'ru', ['ru', 'uz']), 'язык уже тот');
    assert_same(null, PdfController::languageTarget('xx', '/uploads/public/a.pdf', 'ru', ['ru', 'uz']), 'чужой код');
    assert_same(null, PdfController::languageTarget('', '/uploads/public/a.pdf', 'ru', ['ru', 'uz']));
});

test('Модуль и воркер PDF.js отдаются расширением, которое знает любой Apache', function (): void {
    // .mjs хостинг отдавал как application/octet-stream, и при nosniff
    // браузер модуль не выполнял: просмотрщик падал только на живом сайте.
    assert_true(is_file(APP_ROOT . '/public/assets/vendor/pdfjs/pdf.min.js'));
    assert_true(is_file(APP_ROOT . '/public/assets/vendor/pdfjs/pdf.worker.min.js'));
    assert_same([], glob(APP_ROOT . '/public/assets/vendor/pdfjs/*.mjs') ?: []);
    foreach (['/.htaccess', '/public/.htaccess'] as $file) {
        assert_contains('AddType application/wasm .wasm', (string) file_get_contents(APP_ROOT . $file));
    }
});
