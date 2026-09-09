<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Search;
use App\Core\SearchQuery;
use App\Models\Project;

test('SearchQuery: RU/UZ нормализация и транслитерация', function (): void {
    assert_same("o'zbekiston e'lon", SearchQuery::normalize(' OʻZBEKISTON  E’LON '));
    assert_true(in_array('ўзбекистон', SearchQuery::variants("o'zbekiston"), true));
    assert_true(in_array("o'zbekiston", SearchQuery::variants('ўзбекистон'), true));
    assert_true(in_array('йўл', SearchQuery::variants('yoʻl'), true));
    assert_true(in_array('конституция', SearchQuery::variants('konstitutsiya'), true));
    assert_true(in_array('елка', SearchQuery::variants('Ёлка'), true));
});

test('Site search: запрос кириллицей находит узбекский текст латиницей', function (): void {
    if (!Database::isConnected()) {
        return;
    }
    $marker = 'uzsearch' . bin2hex(random_bytes(3));
    $id = Project::create([
        'title' => "O'zbekiston madaniyati {$marker}",
        'slug' => $marker,
        'description' => 'Milliy sanʼat loyihasi',
        'cover_image' => null,
        'status' => 'published',
        'is_featured' => false,
        'sort_order' => 0,
    ]);
    try {
        $results = Search::site('ўзбекистон ' . $marker);
        assert_true(in_array("O'zbekiston madaniyati {$marker}", array_column($results, 'title'), true));
    } finally {
        Project::forceDelete($id);
    }
});

test('Site search: будущая опубликованная новость не попадает в выдачу', function (): void {
    if (!Database::isConnected()) {
        return;
    }
    $pdo = Database::pdo();
    $marker = 'futuresearch' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        "INSERT INTO news (title, slug, content, status, published_at, created_at)
         VALUES (:title, :slug, '', 'published', DATE_ADD(NOW(), INTERVAL 2 DAY), NOW())"
    );
    $stmt->execute([':title' => $marker, ':slug' => $marker]);
    $id = (int) $pdo->lastInsertId();
    try {
        assert_false(in_array($marker, array_column(Search::site($marker), 'title'), true));
    } finally {
        $pdo->prepare('DELETE FROM news WHERE id = :id')->execute([':id' => $id]);
    }
});
