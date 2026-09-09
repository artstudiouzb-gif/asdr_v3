<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Search;
use App\Models\Project;

// Полнотекстовый поиск по сайту: многословный AND + охват проектов.

test('Site search: все слова запроса должны встретиться (AND), проекты в выдаче', function () {
    if (!Database::isConnected()) {
        return;
    }
    $marker = 'zztest' . bin2hex(random_bytes(2));
    $id = Project::create([
        'title' => 'Проект ' . $marker . ' модернизация',
        'slug' => '_srch_' . $marker,
        'description' => null,
        'cover_image' => null,
        'status' => 'published',
        'is_featured' => false,
        'sort_order' => 0,
    ]);

    // Оба слова присутствуют → находим.
    $hit = Search::site($marker . ' модернизация', 40);
    $titles = array_map(static fn ($r) => $r['title'], $hit);
    $found = false;
    foreach ($titles as $t) {
        if (str_contains($t, $marker)) { $found = true; break; }
    }
    assert_true($found, 'проект найден по двум присутствующим словам');

    // Добавляем слово, которого нет в записи → AND отсекает.
    $miss = Search::site($marker . ' отсутствующееслово', 40);
    $foundMiss = false;
    foreach ($miss as $r) {
        if (str_contains($r['title'], $marker)) { $foundMiss = true; break; }
    }
    assert_true(!$foundMiss, 'при лишнем несовпадающем слове запись не находится (AND)');

    Project::forceDelete($id);
});
