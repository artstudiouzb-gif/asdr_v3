<?php

declare(strict_types=1);

use App\Models\NewsPoll;

test('Опрос корректно обрабатывает пустые результаты и отсутствующий голос', function (): void {
// 1. Форматирование результатов пустой структуры опроса
$res = NewsPoll::getResults(999999, ["Вариант A", "Вариант B"]);
assert_same(0, $res["total"]);

// 2. Проверка функции проверки голоса
assert_true(!NewsPoll::hasVoted(999999, "test_voter_hash"));
});
