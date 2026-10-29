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
    // Загрузки лежат одним каталогом: короткий адрес книги несёт одно имя.
    $dir = APP_ROOT . '/public/uploads/public/_pdf_book_test';
    @mkdir($dir, 0755, true);
    file_put_contents(APP_ROOT . '/public/uploads/public/_pdf_book_test_report.pdf', '%PDF-1.4');
    file_put_contents($dir . '/nested.pdf', '%PDF-1.4');
    file_put_contents($dir . '/rules.docx', 'x');

    return ['dir' => $dir, 'url' => '/uploads/public/_pdf_book_test_report.pdf'];
}

function pdf_book_cleanup(string $dir): void
{
    @unlink(APP_ROOT . '/public/uploads/public/_pdf_book_test_report.pdf');
    @unlink($dir . '/nested.pdf');
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
        assert_same('/book/_pdf_book_test_report', $file['href']);
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
            // во вложенном каталоге короткий адрес не выразить
            '/uploads/public/_pdf_book_test/nested.pdf',
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
        assert_contains('href="/book/_pdf_book_test_report"', $book);
        assert_contains('#tabler-book"', $book);
        // Книга — страница сайта со ссылкой «Назад»: открывается в той же вкладке.
        assert_not_contains('/book/_pdf_book_test_report" target=', $book);

        // Умолчание — прежнее поведение: ссылка на сам файл.
        $plain = $render([]);
        assert_contains('href="' . htmlspecialchars($fixture['url'], ENT_QUOTES) . '"', $plain);
        assert_not_contains('/book/', $plain);
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

test('Адрес книги короткий и ведёт только к своему PDF', function (): void {
    $fixture = pdf_book_fixture();
    try {
        assert_same($fixture['url'], PdfViewer::fileFromBookName('_pdf_book_test_report'));
        assert_same($fixture['url'], PdfViewer::fileFromBookName('_pdf_book_test_report.pdf'));
        assert_same('/uz/book/_pdf_book_test_report', PdfViewer::bookUrl($fixture['url'], 'uz'));
        assert_same(null, PdfViewer::fileFromBookName('_pdf_book_test/nested'), 'каталог в имени');
        assert_same(null, PdfViewer::fileFromBookName('_pdf_book_test%2Fnested'), 'закодированный каталог');
        assert_same(null, PdfViewer::fileFromBookName('..%2F..%2Fconfig%2Fx'), 'выход из каталога');
        assert_same(null, PdfViewer::fileFromBookName(''), 'пустое имя');
        assert_same(null, PdfViewer::fileFromBookName('missing-file'), 'файла нет');
    } finally {
        pdf_book_cleanup($fixture['dir']);
    }
});

test('Ссылка «Назад» ведёт туда, где посетитель видел страницу', function (): void {
    assert_same('/', PdfViewer::pageUrl(['slug' => 'home', 'is_home' => 1, 'lang' => 'ru']));
    assert_same('/uz/tahlil', PdfViewer::pageUrl(['slug' => 'tahlil', 'lang' => 'uz']));
    // Шапка раздела отвечает 301 на сам раздел — ведём сразу туда.
    assert_same('/news', PdfViewer::pageUrl(['slug' => 'press', 'section' => 'news', 'lang' => 'ru']));
    assert_same('/projects/park', PdfViewer::pageUrl(['slug' => 'park', 'entity_type' => 'project', 'lang' => 'ru']));
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

test('Шапка книги берёт название документа и страницу из блока «Документы» (БД)', function (): void {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $slug = 'book-src-' . bin2hex(random_bytes(3));
    $pid = \App\Models\Page::create(['title' => 'Исследования', 'slug' => $slug, 'meta_title' => null, 'meta_description' => null, 'status' => 'published', 'is_home' => 0, 'layout_type' => 'no_sidebar']);
    $lang = (string) $pdo->query('SELECT lang FROM pages WHERE id = ' . (int) $pid)->fetchColumn();
    $data = json_encode(['items' => [
        ['title' => 'Другой документ', 'url' => '/uploads/public/other.pdf'],
        ['title' => 'Годовой отчёт', 'url' => '/uploads/public/_pdf_book_test_report.pdf'],
    ]], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO blocks (page_id,lang,type,data,sort_order) VALUES (?,?,?,?,0)')->execute([$pid, $lang, 'docs_list', $data]);

    $source = PdfViewer::documentSource('/uploads/public/_pdf_book_test_report.pdf', $lang);
    assert_same('Годовой отчёт', $source['title'] ?? null);
    assert_same('Исследования', $source['page_title'] ?? null);
    assert_contains('/' . $slug, $source['page_url'] ?? '');

    // Файла нет ни в одном блоке — шапка остаётся с именем файла и без «Назад».
    assert_same(null, PdfViewer::documentSource('/uploads/public/_pdf_book_nowhere.pdf', $lang));
});
