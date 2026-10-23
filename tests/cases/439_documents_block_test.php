<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\DocumentPresenter;

/*
 * Документы: описание, обложка и файлы по языкам.
 *
 * Поле «Мета» рассчитано на подпись формата и выводится капслоком, а
 * редакторы писали туда абзац о документе — другого места не было. Теперь у
 * документа есть описание из мини-редактора, необязательная обложка и
 * версии файла на языках сайта: посетитель скачивает версию на языке
 * страницы, остальные видит рядом.
 */

/** @return list<array<string, mixed>> */
function documents_test_languages(): array
{
    return [
        ['code' => 'ru', 'name' => 'Русский'],
        ['code' => 'uz', 'name' => 'Oʻzbekcha'],
        ['code' => 'en', 'name' => 'English'],
    ];
}

test('Документ: главный файл — версия на языке страницы, переводы рядом', function (): void {
    $doc = [
        'title' => 'Отчёт',
        'url' => '/uploads/public/report.pdf',
        'files' => ['uz' => '/uploads/public/report-uz.pdf', 'en' => '/uploads/public/report-en.docx'],
    ];

    $uz = DocumentPresenter::files($doc, 'uz', documents_test_languages());
    assert_same('/uploads/public/report-uz.pdf', $uz[0]['url']);
    assert_true($uz[0]['current']);
    // Общий файл и английская версия — переводы, по порядку языков сайта.
    assert_same(['/uploads/public/report-en.docx', '/uploads/public/report.pdf'], array_column(array_slice($uz, 1), 'url'));

    // Версии на языке страницы нет — главным становится общий файл.
    $ru = DocumentPresenter::files($doc, 'ru', documents_test_languages());
    assert_same('/uploads/public/report.pdf', $ru[0]['url']);
    assert_same('', $ru[0]['lang']);

    // Чужая схема в адресе перевода не выводится.
    $bad = DocumentPresenter::files(['files' => ['uz' => 'javascript:alert(1)']], 'uz', documents_test_languages());
    assert_same([], $bad);

    $prepared = DocumentPresenter::prepare($doc, 'en', documents_test_languages());
    assert_same('docx', $prepared['extension']);
    assert_same('doc', DocumentPresenter::family($prepared['extension']));
});

test('Документ: описание — разметка мини-редактора без опасного и без капслока', function (): void {
    $rendered = BlockRenderer::render([
        'id' => 4391,
        'type' => 'docs_list',
        'data' => json_encode(['variant' => 'acts', 'items' => [[
            'title' => 'Предложения по развитию отрасли',
            'description' => '<p>Исследование <strong>посвящено</strong> отрасли.</p><script>alert(1)</script>',
            'url' => '/uploads/public/study.pdf',
        ]]], JSON_UNESCAPED_UNICODE),
    ]);
    $html = (string) $rendered['html'];

    assert_contains('class="doc-desc act-card__summary"', $html);
    assert_contains('<strong>посвящено</strong>', $html);
    assert_not_contains('<script>alert', $html);
    // Без номера название — заголовок карточки.
    assert_contains('act-card--no-number', $html);
    // Лист формата вместо значка 24px.
    assert_contains('doc-sheet doc-sheet--pdf', $html);
    assert_contains('class="doc-files act-card__foot"', $html);
    // Карточка — не ссылка: ссылки переводов внутри неё были бы невозможны.
    assert_not_contains('<a class="feature-card act-card"', $html);
});

test('Документ: длинная «мета» — это описание, подпись формата считается заново', function (): void {
    $long = 'Исследование посвящено развитию парфюмерно-косметической отрасли и перспективам её роста.';
    $prepared = DocumentPresenter::prepare(['title' => 'Т', 'meta' => $long, 'url' => '/uploads/public/a.pdf'], 'ru', documents_test_languages());
    assert_contains($long, $prepared['description']);
    assert_contains('PDF', $prepared['meta']);

    // Короткая подпись остаётся подписью.
    $short = DocumentPresenter::prepare(['title' => 'Т', 'meta' => 'PDF, 2 МБ', 'url' => '/uploads/public/a.pdf'], 'ru', documents_test_languages());
    assert_same('', $short['description']);
    assert_same('PDF, 2 МБ', $short['meta']);
});

test('Документ: обложка встаёт в описание, лист формата остаётся', function (): void {
    $rendered = BlockRenderer::render([
        'id' => 4392,
        'type' => 'docs_list',
        'data' => json_encode(['variant' => 'grid', 'items' => [[
            'title' => 'Издание',
            'cover' => '/uploads/public/cover.jpg',
            'url' => '/uploads/public/book.pdf',
        ], [
            'title' => 'Без обложки',
            'cover' => 'javascript:alert(1)',
            'url' => '/uploads/public/book.pdf',
        ]]], JSON_UNESCAPED_UNICODE),
    ]);
    $html = (string) $rendered['html'];

    assert_contains('class="doc-desc doc-desc--cover"', $html);
    assert_contains('doc-desc__img', $html);
    assert_not_contains('javascript:', $html);
    assert_same(2, substr_count($html, 'doc-sheet--pdf'), 'лист формата есть у обеих карточек');
    assert_same(1, substr_count($html, 'doc-desc__cover'), 'негодная обложка не выводится');
});

test('Документ: своя подпись формата главнее автоматической', function (): void {
    $rendered = BlockRenderer::render([
        'id' => 4393,
        'type' => 'docs_list',
        'data' => json_encode(['variant' => 'links', 'items' => [[
            'title' => 'Стратегия',
            'meta' => 'PDF · 2.4 МБ',
            'url' => '/uploads/public/s.pdf',
        ]]], JSON_UNESCAPED_UNICODE),
    ]);

    assert_contains('<span class="doc-files__meta">PDF · 2.4 МБ</span>', (string) $rendered['html']);
});

test('Документ: своё название ссылки вместо «Скачать» и названия языка', function (): void {
    $doc = [
        'title' => 'Постановление',
        'url' => '/uploads/public/act.pdf',
        'link_label' => 'Текст постановления',
        'files' => ['uz' => '/uploads/public/act-uz.pdf', 'en' => '/uploads/public/act-en.pdf'],
        'file_labels' => ['uz' => 'Oʻzbekcha matn'],
    ];
    $html = render_view('templates/blocks/partials/document_files.php', [
        'presented' => DocumentPresenter::prepare($doc, 'ru', documents_test_languages()),
        'filesClass' => '',
    ]);

    assert_contains('<span class="doc-files__action">Текст постановления</span>', $html);
    assert_contains('Oʻzbekcha matn', $html);
    // Без своего названия перевод подписан языком.
    assert_contains('English', $html);
    assert_not_contains('>Скачать<', $html);
});

test('Акт: название стоит в шапке рядом с листом формата, номера по порядку нет', function (): void {
    $html = render_view('templates/blocks/partials/act_card.php', [
        'doc' => ['title' => 'Предложения по развитию отрасли', 'url' => '/uploads/public/a.pdf', 'description' => '<p>Текст</p>', 'cover' => ''],
        'docIndex' => 0,
    ]);

    // Лист и название — соседи внутри одной шапки, и шапка не скрыта от диктора.
    assert_true((bool) preg_match('~<div class="act-card__editorial-head">\s*<span class="doc-visual[^"]*"[^>]*>.*?</span>\s*<div class="act-card__heading">.*?act-card__desc">Предложения по развитию отрасли</p>~s', $html), 'название рядом с листом');
    assert_not_contains('act-card__index', $html);
    assert_contains('<div class="doc-desc__text"><p>Текст</p></div>', $html);
});

test('Акт с обложкой: фото — своя колонка карточки, в описании его нет', function (): void {
    $html = render_view('templates/blocks/partials/act_card.php', [
        'doc' => ['title' => 'Издание', 'url' => '/uploads/public/a.pdf', 'description' => '<p>Текст</p>', 'cover' => '/uploads/public/cover.jpg'],
    ]);

    assert_contains('act-card--media', $html);
    assert_true((bool) preg_match('~<div class="act-card__frame">\s*<span class="act-card__media"[^>]*>.*?</span>\s*<div class="act-card__main">~s', $html), 'фото слева, содержимое справа');
    assert_not_contains('doc-desc--cover', $html);
    assert_contains('<div class="doc-desc__text"><p>Текст</p></div>', $html);
});

test('Документы: «Авто» листает каруселью, когда не помещаются в два ряда, но не архив', function (): void {
    assert_true(!DocumentPresenter::carousel('auto', 4, 2), 'два ряда по две — сетка');
    assert_true(DocumentPresenter::carousel('auto', 5, 2), 'пятый документ — карусель');
    assert_true(!DocumentPresenter::carousel('auto', DocumentPresenter::CAROUSEL_MAX_AUTO + 1, 2), 'архив — сетка с поиском');
    assert_true(DocumentPresenter::carousel('slider', 2, 4), 'явная карусель');
    assert_true(!DocumentPresenter::carousel('grid', 20, 2), 'явная сетка');
    assert_true(!DocumentPresenter::carousel('slider', 1, 2), 'одному документу листать нечего');
});

test('Документы из каталога: роль поля — по его виду, перевод поверх основного языка', function (): void {
    $fields = [
        ['name' => 'nomer', 'field_type' => 'text'],
        ['name' => 'opis', 'field_type' => 'textarea'],
        ['name' => 'fayl', 'field_type' => 'file'],
        ['name' => 'oblozhka', 'field_type' => 'image'],
        ['name' => 'data', 'field_type' => 'date'],
        ['name' => 'vtoroy_fayl', 'field_type' => 'file'],
    ];
    $entries = [
        ['id' => 7, 'title' => 'Постановление', 'data' => json_encode([
            'nomer' => 'ПП-12', 'opis' => "Первый абзац.\n\nВторой & последний.", 'fayl' => '/uploads/public/pp.pdf',
            'oblozhka' => 'javascript:alert(1)', 'data' => '2026-03-12', 'vtoroy_fayl' => '/uploads/public/other.pdf',
        ], JSON_UNESCAPED_UNICODE)],
        ['id' => 8, 'title' => '', 'data' => '{}'],
    ];
    $translations = [7 => ['title' => 'Qaror', 'data' => ['opis' => '', 'nomer' => 'PQ-12']]];

    $items = \App\Core\CatalogDocuments::map($fields, $entries, $translations);

    assert_same(1, count($items), 'запись без названия не выводится');
    $doc = $items[0];
    assert_same('Qaror', $doc['title']);
    assert_same('PQ-12', $doc['number']);
    assert_same('/uploads/public/pp.pdf', $doc['url'], 'файл — первое поле-файл');
    assert_same('', $doc['cover'], 'опасный адрес обложки не выводится');
    assert_same('12.03.2026', $doc['date']);
    // Пустое поле перевода уступает основному языку; простой текст — абзацами.
    assert_same('<p>Первый абзац.</p><p>Второй &amp; последний.</p>', $doc['description']);
});
