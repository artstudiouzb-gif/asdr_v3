<?php

declare(strict_types=1);

use App\Core\Scheduler;

/*
 * Одна строка cron вместо полутора десятков (FUNC-3): планировщик каждую
 * минуту запускает воркеры, которым пора, и не теряет задачу, чья минута
 * пришлась на простой.
 */

test('Разбор cron: звёздочка, шаг, диапазон, список, день недели', function (): void {
    $t = mktime(3, 30, 0, 11, 2, 2026); // понедельник, 2 ноября 2026, 03:30
    assert_true(Scheduler::matches('* * * * *', $t));
    assert_true(Scheduler::matches('30 3 * * *', $t));
    assert_true(!Scheduler::matches('31 3 * * *', $t));
    assert_true(Scheduler::matches('*/15 * * * *', $t));
    assert_true(!Scheduler::matches('*/20 * * * *', $t));
    assert_true(Scheduler::matches('0-45 2-4 * * *', $t));
    assert_true(Scheduler::matches('10,30,50 * * * *', $t));
    assert_true(Scheduler::matches('30 3 * * 1', $t), 'понедельник');
    assert_true(!Scheduler::matches('30 3 * * 0', $t), 'не воскресенье');
    assert_true(Scheduler::matches('30 3 2 * 5', $t), 'день месяца или недели — достаточно одного');
    assert_true(!Scheduler::matches('мусор', $t));
    $sunday = mktime(3, 40, 0, 11, 1, 2026);
    assert_true(Scheduler::matches('40 3 * * 7', $sunday), '7 — тоже воскресенье');
});

test('Пропущенная минута не теряет задачу, первый проход не наверстывает', function (): void {
    $night = mktime(3, 0, 0, 11, 2, 2026);
    assert_true(!Scheduler::isDue('0 3 * * *', null, $night + 5 * 60), 'без прошлого запуска — только текущая минута');
    assert_true(Scheduler::isDue('0 3 * * *', null, $night + 20));
    assert_true(Scheduler::isDue('0 3 * * *', $night - 86400, $night + 5 * 60), 'минута 03:00 пропущена — догоняем в 03:05');
    assert_true(!Scheduler::isDue('0 3 * * *', $night + 30, $night + 5 * 60), 'уже запускали в 03:00');
    assert_true(!Scheduler::isDue('* * * * *', $night + 10, $night + 50), 'в ту же минуту второй раз не запускаем');
    assert_true(Scheduler::isDue('* * * * *', $night + 10, $night + 70));
});

test('Проход запускает только то, чему пора, и помнит запуск', function (): void {
    $file = Scheduler::stateFile();
    $saved = is_file($file) ? (string) file_get_contents($file) : null;
    @unlink($file);
    try {
        $now = mktime(3, 0, 0, 11, 2, 2026);
        $spawned = [];
        $started = Scheduler::tick($now, function (string $task) use (&$spawned): bool {
            $spawned[] = $task;
            return $task !== 'backup';
        });
        assert_true(in_array('mail', $spawned, true), 'каждую минуту');
        assert_true(in_array('backup', $spawned, true), '03:00 — бэкап');
        assert_true(!in_array('backup', $started, true), 'неудачный запуск не считается запущенным');
        assert_true(!in_array('digest', $spawned, true), 'дайджест — по понедельникам в 9');
        assert_same($now, Scheduler::lastRuns()['backup'] ?? null, 'попытка отмечена и при неудаче');

        $again = Scheduler::tick($now + 20, static fn (string $task): bool => true);
        assert_same([], $again, 'в ту же минуту — ничего');
    } finally {
        @unlink($file);
        if ($saved !== null) {
            file_put_contents($file, $saved);
        }
    }
});

test('У каждой задачи есть скрипт и подпись', function (): void {
    foreach (Scheduler::TASKS as $task => [$script, $cron]) {
        assert_true(is_file(APP_ROOT . '/app/Console/' . $script), $task . ': нет ' . $script);
        assert_true(isset(Scheduler::LABELS[$task]), $task . ': нет подписи');
        assert_true(count(preg_split('/\s+/', $cron) ?: []) === 5, $task . ': пять полей cron');
    }
    assert_true(!Scheduler::spawn('../../etc/passwd'), 'чужое имя не запускается');
});
