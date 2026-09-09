<?php

declare(strict_types=1);

/*
 * Отчёт по бюджетам качества: было / стало / сколько осталось до падения.
 *
 * Величины и способ их замера объявлены один раз — в tests/budgets.php, там же
 * их читают сторожа в tests/cases. Здесь только вывод: сторож отвечает на
 * вопрос «уже нарушено?», отчёт — на вопрос «сколько ещё можно?», и второй
 * вопрос без отчёта задать было нечем: зелёный прогон не печатает ни текущего
 * значения, ни потолка.
 *
 *   php .claude/skills/quality-budgets/report.php          # таблица
 *   php .claude/skills/quality-budgets/report.php --json   # для скриптов
 *   php .claude/skills/quality-budgets/report.php --strict  # код 1 при превышении
 *
 * Базы данных не требует: всё считается по файлам репозитория.
 */

if (PHP_SAPI !== 'cli') {
    exit('Только из командной строки.');
}

$root = dirname(__DIR__, 3);
require $root . '/tests/bootstrap.php';
require $root . '/tests/lib.php';

$asJson = in_array('--json', $argv, true);
$strict = in_array('--strict', $argv, true);

$rows = [];
$exceeded = 0;
foreach (array_keys(quality_budgets()) as $id) {
    $budget = quality_budget($id);
    $value = $budget['value'];

    $rows[] = [
        'id' => $id,
        'title' => $budget['title'],
        'unit' => $budget['unit'],
        'value' => $value,
        'ceiling' => $budget['ceiling'],
        'headroom' => $value === null ? null : $budget['ceiling'] - $value,
        'guard' => $budget['guard'],
        'why' => $budget['why'],
        'detail' => $budget['detail'],
    ];

    if ($value !== null && $value > $budget['ceiling']) {
        $exceeded++;
    }
}

if ($asJson) {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($strict && $exceeded > 0 ? 1 : 0);
}

/** Ширина строки в символах, а не в байтах: заголовки по-русски. */
$w = static fn (string $s): int => mb_strlen($s);
$pad = static function (string $s, int $width) use ($w): string {
    return $s . str_repeat(' ', max(0, $width - $w($s)));
};

$head = ['Бюджет', 'Сейчас', 'Потолок', 'Запас', 'Статус'];
$table = [];
foreach ($rows as $row) {
    // Байты показываем килобайтами: 51 856 глазом не сравнить с 53 248.
    $fmt = static function (?int $n) use ($row): string {
        if ($n === null) {
            return '—';
        }

        return $row['unit'] === 'Б'
            ? number_format($n / 1024, 1, ',', ' ') . ' КБ'
            : (string) $n;
    };

    // Запас — это не «хорошо», а невыбранный храповик: рано или поздно его
    // израсходует случайная правка. Здоровое состояние счётного бюджета —
    // потолок ровно по факту, поэтому окликаем как раз строку с запасом.
    // У веса бандлов иначе: там потолок поставлен с запасом сознательно, ради
    // порога, который не дёргает на каждый килобайт, — такую строку не трогаем.
    if ($row['value'] === null) {
        $status = 'не измерено';
    } elseif ($row['headroom'] < 0) {
        $status = 'ПРЕВЫШЕН';
    } elseif ($row['headroom'] === 0) {
        $status = 'по факту';
    } elseif ($row['unit'] === 'Б') {
        $status = 'ок';
    } else {
        $status = 'запас — опустить';
    }

    $table[] = [
        $row['title'],
        $fmt($row['value']),
        $fmt($row['ceiling']),
        $row['headroom'] === null ? '—' : ($row['headroom'] < 0 ? '+' . -$row['headroom'] : $fmt($row['headroom'])),
        $status,
    ];
}

$widths = [];
foreach ($head as $i => $title) {
    $widths[$i] = $w($title);
    foreach ($table as $line) {
        $widths[$i] = max($widths[$i], $w($line[$i]));
    }
}

$line = [];
foreach ($head as $i => $title) {
    $line[] = $pad($title, $widths[$i]);
}
echo "\n" . implode('  ', $line) . "\n";
echo str_repeat('-', array_sum($widths) + 2 * count($widths)) . "\n";

foreach ($table as $row) {
    $out = [];
    foreach ($row as $i => $cell) {
        $out[] = $pad($cell, $widths[$i]);
    }
    echo implode('  ', $out) . "\n";
}

echo "\n";
foreach ($rows as $row) {
    $slack = $row['value'] !== null && $row['headroom'] > 0 && $row['unit'] !== 'Б';
    if ($row['value'] !== null && $row['headroom'] >= 0 && !$slack) {
        continue;
    }

    if ($row['value'] === null) {
        $label = 'Не измерено: ';
    } elseif ($row['headroom'] < 0) {
        $label = 'Превышен: ';
    } else {
        $label = 'Невыбранный запас (' . $row['headroom'] . '): ';
    }

    echo $label . $row['title'] . "\n";
    echo '  сторож: ' . $row['guard'] . "\n";
    echo '  почему: ' . $row['why'] . "\n";
    if ($row['detail'] !== '') {
        echo '  где: ' . $row['detail'] . "\n";
    }
}

echo $exceeded > 0
    ? "Превышено бюджетов: {$exceeded}. Потолок поднимают только вместе с объяснением в том же коммите.\n"
    : "Все бюджеты в пределах потолка.\n";

exit($strict && $exceeded > 0 ? 1 : 0);
