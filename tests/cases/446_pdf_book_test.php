<?php

declare(strict_types=1);

use App\Controllers\Site\PdfController;
use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\DocumentPresenter;
use App\Core\PdfViewer;

/*
 * PDF книгой с листанием. Книга подходит брошюре и отчёту, но не
 * постановлению: страница в ней картинка, текст не выделить и не найти.
 * Поэтому вид выбирается у документа (или по умолчанию у блока), а не
 * включается всем PDF сайта разом.
 */

/** @return array{dir:string,url:string} */
function pdf_book_fixture(): array
{
    $dir = APP_ROOT . '/public/uploads/public/_pdf_book_test';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/Отчёт 2025.pdf', '%PDF-1.4');
    file_put_contents($dir . '/rules.docx', 'x');

    return ['dir' => $dir, 'url' => '/uploads/public/_pdf_book_test/' . rawurlencode('Отчёт 2025.pdf')];
}

function pdf_book_cleanup(string $dir): void
{
    @unlink($dir . '/Отчёт 2025.pdf');
    @unlink($dir . '/rules.docx');
    @rmdir($dir);
}

test('Вид PDF: свой у документа главнее настройки блока', function (): void {
    $items = DocumentPresenter::withOpenAs([
        ['title' => 'Без выбора'],
        ['title' => 'Книга', 'open_as' => 'book'],
        ['title' => 'Документ', 'open_as' => 'document'],
        ['title' => 'Подделка', 'open_as' => 'javascript'],
    ], 'book');
    assert_same(['book', 'book', 'document', 'book'], array_column($items, 'open_as'));

    // Неизвестное умолчание блока — обычный документ.
    assert_same(['document'], array_column(DocumentPresenter::withOpenAs([['title' => 'А']], 'xx'), 'open_as'));
});

test('Книга ведёт только свой PDF на страницу листания', function (): void {
    $fixture = pdf_book_fixture();
    try {
        $languages = [['code' => 'ru', 'name' => 'Русский']];
        $book = DocumentPresenter::prepare(['title' => 'Отчёт', 'url' => $fixture['url'], 'open_as' => 'book'], 'ru', $languages);
        $file = $book['files'][0];
        assert_true($file['book']);
        assert_same('/pdf?file=' . rawurlencode($fixture['url']) . '&view=book', $file['href']);
        // Адрес самого файла остаётся — по нему считается размер и формат.
        assert_same($fixture['url'], $file['url']);

        $plain = DocumentPresenter::prepare(['title' => 'Отчёт', 'url' => $fixture['url'], 'open_as' => 'document'], 'ru', $languages);
        assert_false($plain['files'][0]['book']);
        assert_same($fixture['url'], $plain['files'][0]['href']);

        // Не PDF, чужой файл и файл, которого нет, остаются прямой ссылкой.
        foreach ([
            '/uploads/public/_pdf_book_test/rules.docx',
            'https://example.com/uploads/public/report.pdf',
            '/uploads/public/_pdf_book_test/missing.pdf',
        ] as $url) {
            $other = DocumentPresenter::prepare(['title' => 'Т', 'url' => $url, 'open_as' => 'book'], 'ru', $languages);
            assert_false($other['files'][0]['book'], $url);
            assert_same($url, $other['files'][0]['href'], $url);
        }
    } finally {
        pdf_book_cleanup($fixture['dir']);
    }
});

test('Блок «Документы» выводит ссылку на книгу по настройке блока', function (): void {
    $fixture = pdf_book_fixture();
    try {
        $render = static fn (array $data): string => (string) BlockRenderer::render([
            'id' => 4461,
            'type' => 'docs_list',
            'data' => json_encode($data + ['items' => [['title' => 'Годовой отчёт', 'url' => $fixture['url']]]], JSON_UNESCAPED_UNICODE),
        ])['html'];

        $book = $render(['open_as' => 'book']);
        assert_contains('href="/pdf?file=' . htmlspecialchars(rawurlencode($fixture['url']), ENT_QUOTES) . '&amp;view=book"', $book);
        assert_contains('#tabler-book"', $book);

        // Умолчание — прежнее поведение: ссылка на сам файл.
        $plain = $render([]);
        assert_contains('href="' . htmlspecialchars($fixture['url'], ENT_QUOTES) . '"', $plain);
        assert_not_contains('view=book', $plain);
    } finally {
        pdf_book_cleanup($fixture['dir']);
    }
});

test('Настройка «Открывать PDF» объявлена в схеме блока «Документы»', function (): void {
    $schema = BlockFieldSchema::all()['docs_list'];
    assert_true(isset($schema['open_as']));
    assert_same('document', BlockFieldSchema::normalize('docs_list', ['open_as' => 'xx'], 'ru')['open_as']);
    assert_same('book', BlockFieldSchema::normalize('docs_list', ['open_as' => 'book'], 'ru')['open_as']);
});

test('Переход на язык сохраняет вид книги', function (): void {
    assert_same(
        '/uz/pdf?file=%2Fuploads%2Fpublic%2Fa.pdf&view=book',
        PdfController::languageTarget('uz', '/uploads/public/a.pdf', 'ru', ['ru', 'uz'], true)
    );
    assert_same(
        '/uz/pdf?file=%2Fuploads%2Fpublic%2Fa.pdf',
        PdfController::languageTarget('uz', '/uploads/public/a.pdf', 'ru', ['ru', 'uz'])
    );
});

test('Страница книги подключает листание со своего домена', function (): void {
    $fixture = pdf_book_fixture();
    try {
        assert_same(null, PdfViewer::bookUrl('/uploads/public/_pdf_book_test/' . str_repeat('a', 600) . '.pdf'), 'слишком длинный путь');
        assert_true(is_file(APP_ROOT . '/public/assets/vendor/page-flip/page-flip.browser.js'));
        assert_true(is_file(APP_ROOT . '/public/assets/vendor/page-flip/LICENSE'));
    } finally {
        pdf_book_cleanup($fixture['dir']);
    }
});
