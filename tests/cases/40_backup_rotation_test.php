<?php

declare(strict_types=1);

use App\Core\Backup;

test('Backup::selectForDeletion: дневные хранятся, недельные — по одной, старые удаляются', function () {
    $now = strtotime('2026-07-08 12:00:00');
    $day = 86400;

    $files = [
        '/b/fresh1.zip' => $now - 1 * $day,        // свежая — хранить
        '/b/fresh6.zip' => $now - 6 * $day,        // свежая — хранить
        '/b/w26_new.zip' => $now - 10 * $day,       // 2026-06-28, ISO-неделя 26 — самая свежая недели, хранить
        '/b/w26_old.zip' => $now - 12 * $day,       // 2026-06-26, та же неделя — удалить
        '/b/w25.zip' => $now - 20 * $day,       // 2026-06-18, неделя 25 — хранить
        '/b/ancient.zip' => $now - 70 * $day,       // старше окна недель — удалить
    ];

    $delete = Backup::selectForDeletion($files, $now, 7, 4);
    sort($delete);
    assert_same(['/b/ancient.zip', '/b/w26_old.zip'], $delete);
});

test('Backup::selectForDeletion: порядок обхода не влияет на выбор внутри недели', function () {
    $now = strtotime('2026-07-08 12:00:00');
    $day = 86400;

    // Сначала старая копия недели, потом свежая — свежая должна вытеснить старую.
    $files = [
        '/b/w26_old.zip' => $now - 12 * $day,
        '/b/w26_new.zip' => $now - 10 * $day,
    ];
    assert_same(['/b/w26_old.zip'], Backup::selectForDeletion($files, $now, 7, 4));

    // Обратный порядок — результат тот же.
    $files = array_reverse($files, true);
    assert_same(['/b/w26_old.zip'], Backup::selectForDeletion($files, $now, 7, 4));
});

test('Backup::selectForDeletion: пустой список и нулевые лимиты безопасны', function () {
    assert_same([], Backup::selectForDeletion([], time(), 7, 4));

    // keep_weekly = 0: всё старше дневного окна удаляется.
    $now = strtotime('2026-07-08 12:00:00');
    $files = ['/b/old.zip' => $now - 10 * 86400, '/b/fresh.zip' => $now - 86400];
    assert_same(['/b/old.zip'], Backup::selectForDeletion($files, $now, 7, 0));
});
