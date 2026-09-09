<?php

declare(strict_types=1);

use App\Core\ContentFields;
use App\Models\ContentEntry;
use App\Models\ContentType;

// --- Юнит: рендер значения поля для фронтенда (без БД, кроме relation) ---

test('ContentFields::displayValue безопасно рендерит по типу поля', function () {
    $text = ContentFields::displayValue(['label' => 'Имя', 'field_type' => 'text', 'options' => []], '<b>x</b>');
    assert_same('&lt;b&gt;x&lt;/b&gt;', $text);

    $area = ContentFields::displayValue(['label' => 'Опис', 'field_type' => 'textarea', 'options' => []], "a\nb");
    assert_contains('<br', $area);

    $date = ContentFields::displayValue(['label' => 'Дата', 'field_type' => 'date', 'options' => []], '2026-03-15');
    assert_same('15.03.2026', $date);

    $img = ContentFields::displayValue(['label' => 'Фото', 'field_type' => 'image', 'options' => []], '/uploads/public/a.png');
    assert_contains('<img src="/uploads/public/a.png"', $img);
    assert_contains('loading="lazy"', $img);

    $file = ContentFields::displayValue(['label' => 'Документ', 'field_type' => 'file', 'options' => []], '/uploads/public/d.pdf');
    assert_contains('href="/uploads/public/d.pdf"', $file);
    assert_contains('download', $file);

    // Пустые значения дают пустую строку.
    assert_same('', ContentFields::displayValue(['label' => 'x', 'field_type' => 'text', 'options' => []], ''));
    assert_same('', ContentFields::displayValue(['label' => 'x', 'field_type' => 'text', 'options' => []], null));
});

test('ContentType::allPublic содержит стартовые госразделы, скрытые исключены (БД)', function () {
    ensure_test_db();
    $slugs = array_map(static fn ($t) => $t['slug'], ContentType::allPublic());
    foreach (['documenty', 'vakansii', 'tendery'] as $slug) {
        assert_true(in_array($slug, $slugs, true), "публичный тип {$slug} присутствует");
    }

    // Скрытый тип не попадает в allPublic.
    $hidden = 'hidden-' . bin2hex(random_bytes(3));
    $id = ContentType::create($hidden, 'Скрытый', false, 'нет', false);
    $slugs2 = array_map(static fn ($t) => $t['slug'], ContentType::allPublic());
    assert_false(in_array($hidden, $slugs2, true), 'скрытый тип исключён из allPublic');
    ContentType::delete($id);
});

test('ContentEntry::forTypePublic — пагинация, поиск и сортировка (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $type = ContentType::findBySlug('documenty');
    $tid = (int) $type['id'];
    $pdo->prepare('DELETE FROM content_entries WHERE type_id = :t')->execute([':t' => $tid]);

    for ($i = 1; $i <= 15; $i++) {
        ContentEntry::create($tid, 'Документ ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'doc-' . $i, 'published', [
            'category' => $i % 2 ? 'Приказы' : 'Постановления',
        ]);
    }
    // Черновик не должен считаться.
    ContentEntry::create($tid, 'Черновик', 'doc-draft', 'draft', []);

    assert_same(15, ContentEntry::countTypePublic($tid));
    assert_same(12, count(ContentEntry::forTypePublic($tid, '', 'new', 12, 0)));
    assert_same(3, count(ContentEntry::forTypePublic($tid, '', 'new', 12, 12)));
    assert_same(7, ContentEntry::countTypePublic($tid, 'Постановления'));

    $byTitle = ContentEntry::forTypePublic($tid, '', 'title', 1, 0);
    assert_same('Документ 01', $byTitle[0]['title']);

    $pdo->prepare('DELETE FROM content_entries WHERE type_id = :t')->execute([':t' => $tid]);
});

test('Стартовые госразделы имеют ожидаемые поля (БД)', function () {
    ensure_test_db();
    $doc = ContentType::findBySlug('documenty');
    assert_true($doc !== null, 'тип Документы существует');
    $names = array_map(static fn ($f) => $f['name'], ContentType::fields((int) $doc['id']));
    foreach (['doc_number', 'doc_date', 'category', 'summary', 'file'] as $n) {
        assert_true(in_array($n, $names, true), "поле {$n} присутствует");
    }
});
