<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\PageTranslationFile;
use App\Core\Xlsx;
use App\Models\Block;

/**
 * Перевод всех страниц файлом: таблица текстов одного языка туда и
 * обратно. Ключ строки — номер блока исходника и путь к полю, поэтому
 * проверяется сам перевод на странице, а не вид таблицы.
 */

test('Xlsx: таблица переживает запись и чтение, включая формулоподобный текст', function () {
    $rows = [
        ['Ключ', 'Текст'],
        ['a', "- пункт списка\nвторая строка"],
        ['b', '=СУММ(A1) «кавычки» & <теги> Oʻzbekiston'],
        ['c', ''],
    ];
    $path = tempnam(sys_get_temp_dir(), 'xt');
    file_put_contents($path, Xlsx::write($rows, [10, 40]));
    try {
        $read = Xlsx::read($path);
    } finally {
        @unlink($path);
    }
    assert_same($rows[1], $read[1], 'перенос строки и «-» в начале — текст');
    assert_same($rows[2], $read[2], 'экранирование и «=» — текст, а не формула');
    assert_same('c', $read[3][0]);
});

// Файл из чужой программы: элементы с префиксом пространства имён
// (`<x:c>`), пустая строка общего словаря `<si/>`, пустой ряд и ячейка
// inlineStr без `<is>`. Такой файл ронял загрузку на foreach (null), а
// атрибут типа ячейки у элемента из children($ns) не читался вовсе.
test('Xlsx: читает файл с префиксом x: и пустыми ячейками', function () {
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $path = tempnam(sys_get_temp_dir(), 'xt');
    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<x:workbook xmlns:x="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><x:sheets><x:sheet name="S" sheetId="1" r:id="rId1"/></x:sheets></x:workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="ws" Target="worksheets/data.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<x:sst xmlns:x="' . $ns . '"><x:si/><x:si><x:r><x:t>Пере</x:t></x:r><x:r><x:t>вод</x:t></x:r></x:si><x:si><x:t>Ключ</x:t></x:si></x:sst>');
    $zip->addFromString('xl/worksheets/data.xml', '<x:worksheet xmlns:x="' . $ns . '"><x:sheetData>'
        . '<x:row r="1"><x:c r="A1" t="s"><x:v>2</x:v></x:c><x:c r="C1" t="s"><x:v>1</x:v></x:c></x:row>'
        . '<x:row r="2"/>'
        . '<x:row r="3"><x:c r="A3" t="inlineStr"/><x:c r="B3" t="s"><x:v>0</x:v></x:c><x:c r="D3"><x:v>42</x:v></x:c></x:row>'
        . '</x:sheetData></x:worksheet>');
    $zip->close();
    try {
        $rows = Xlsx::read($path);
    } finally {
        @unlink($path);
    }
    assert_same(['Ключ', '', 'Перевод'], $rows[0], 'строка словаря по типу ячейки, пропуск колонки сохраняет позицию');
    assert_same([], $rows[1]);
    assert_same(['', '', '', '42'], $rows[2], 'пустые ячейки — пустые строки, а не падение');
});

test('Xlsx: не таблица — понятная ошибка, а не падение', function () {
    $path = tempnam(sys_get_temp_dir(), 'xt');
    file_put_contents($path, 'не zip');
    try {
        Xlsx::read($path);
        assert_true(false, 'должно было бросить');
    } catch (\InvalidArgumentException $e) {
        assert_contains('XLSX', $e->getMessage());
    } finally {
        @unlink($path);
    }
});

test('Перевод файлом: выгрузка → перевод → русская версия страницы (БД)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $slug = 'tr-file-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO pages (title, slug, `lead`, status, lang, created_at) VALUES ('Biz haqimizda', :s, 'Agentlik haqida', 'published', 'uz', NOW())")
        ->execute([':s' => $slug]);
    $pageId = (int) $pdo->lastInsertId();
    $textId = Block::create($pageId, 'uz', 'text', null, ['title_field' => 'Vazifalar', 'content' => '<p>Strategiya</p>', 'button_url' => '/uz/contacts'], '');
    Block::create($pageId, 'uz', 'cards_grid', null, ['items' => [['title' => 'Tahlil', 'icon' => 'chart', 'url' => '/uz/a']]], '');

    $rows = PageTranslationFile::rows('uz', 'ru');
    $mine = array_values(array_filter($rows, static fn (array $r): bool
        => str_starts_with($r[0], '#') || str_starts_with($r[0], 'page:' . $pageId . ':')
            || preg_match('/^block:(\d+):/', $r[0], $m) === 1 && (int) Block::findById((int) $m[1])['page_id'] === $pageId));
    $keys = array_column($mine, 0);
    assert_contains('block:' . $textId . ':content', implode(' ', $keys));
    assert_true(!in_array('block:' . $textId . ':button_url', $keys, true), 'ссылка — не текст, в таблицу не идёт');
    assert_true(count(array_filter($keys, static fn (string $k): bool => str_ends_with($k, 'items/0/icon'))) === 0, 'иконка — не текст');

    $translations = [
        'page:' . $pageId . ':title' => 'О нас',
        'page:' . $pageId . ':lead' => 'Об Агентстве',
        'block:' . $textId . ':content' => '<p>Стратегия</p>',
    ];
    $table = [$rows[0]];
    foreach ($mine as $r) {
        foreach (preg_grep('/items\/0\/title$/', [$r[0]]) ?: [] as $k) {
            $translations[$k] = 'Анализ';
        }
        $r[4] = $translations[$r[0]] ?? '';
        $table[] = $r;
    }
    // Исходник, изменившийся после выгрузки, не переводится вслепую.
    foreach ($table as $i => $r) {
        if ($r[0] === 'block:' . $textId . ':title_field') {
            $table[$i][3] = 'Eski matn';
            $table[$i][4] = 'Старый текст';
        }
    }

    $result = PageTranslationFile::import($table, ['ru', 'uz'], false);
    assert_same(1, $result['created']);
    assert_same(1, $result['stale'], 'устаревшая строка пропущена и посчитана');

    $stmt = $pdo->prepare("SELECT * FROM pages WHERE lang = 'ru' AND translation_group_id = :g AND deleted_at IS NULL");
    $stmt->execute([':g' => $pageId]);
    $ru = $stmt->fetch();
    assert_true(is_array($ru), 'русская версия создана в группе перевода');
    assert_same('О нас', $ru['title']);
    assert_same('Об Агентстве', $ru['lead']);
    assert_same('draft', $ru['status'], 'перевод приходит черновиком');

    $blocks = Block::forPage((int) $ru['id'], 'ru');
    assert_same(2, count($blocks));
    $text = json_decode((string) $blocks[0]['data'], true);
    assert_same('<p>Стратегия</p>', $text['content']);
    assert_same('Vazifalar', $text['title_field'], 'устаревший перевод не лёг, остался исходник');
    assert_same('/uz/contacts', $text['button_url'], 'ссылка не тронута');
    $cards = json_decode((string) $blocks[1]['data'], true);
    assert_same('Анализ', $cards['items'][0]['title']);
    assert_same('chart', $cards['items'][0]['icon']);

    // Готовый перевод без галочки не затирается.
    $again = PageTranslationFile::import($table, ['ru', 'uz'], false);
    assert_same(0, $again['created'] + $again['updated']);
    assert_same(['Biz haqimizda'], $again['skipped_existing']);
    $over = PageTranslationFile::import($table, ['ru', 'uz'], true);
    assert_same(1, $over['updated']);
    assert_same(2, count(Block::forPage((int) $ru['id'], 'ru')), 'блоки заменены, а не добавлены второй раз');
});

test('Перевод файлом: чужая таблица и чужая пара языков отклоняются', function () {
    foreach ([[['a', 'b']], [['Ключ'], ['#languages:uz>xx']]] as $rows) {
        try {
            PageTranslationFile::import($rows, ['ru', 'uz'], false);
            assert_true(false, 'должно было бросить');
        } catch (\InvalidArgumentException $e) {
            assert_true($e->getMessage() !== '');
        }
    }
});
