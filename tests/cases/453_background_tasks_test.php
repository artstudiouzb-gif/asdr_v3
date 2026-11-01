<?php

declare(strict_types=1);

use App\Core\BackgroundTasks;
use App\Core\Heartbeat;
use App\Models\Setting;

/*
 * Импорт YouTube и Instagram запускается и без cron: сайт раз в час после
 * ответа посетителю проверяет, пора ли. Редактор больше не обязан помнить про
 * кнопку «Обновить сейчас».
 */

/** Снимает и возвращает метки задач, чтобы тест не трогал состояние соседей. */
function background_tasks_markers(?array $restore = null): array
{
    $files = [];
    foreach (array_keys(BackgroundTasks::INTERVALS) as $task) {
        $files[] = APP_ROOT . '/storage/cache/background_task_' . $task . '.txt';
        $files[] = APP_ROOT . '/storage/cache/worker_heartbeat_' . $task . '.txt';
    }
    if ($restore === null) {
        $saved = [];
        foreach ($files as $file) {
            $saved[$file] = is_file($file) ? (string) file_get_contents($file) : null;
            @unlink($file);
        }

        return $saved;
    }
    foreach ($restore as $file => $content) {
        @unlink($file);
        if ($content !== null) {
            file_put_contents($file, $content);
        }
    }

    return [];
}

test('Фоновая задача: пора, если давно не было ни попытки сайта, ни воркера', function (): void {
    $saved = background_tasks_markers();
    try {
        $now = 1_800_000_000;
        assert_same(['youtube', 'instagram'], BackgroundTasks::due($now), 'без меток пора обеим');

        BackgroundTasks::markAttempt('youtube', $now - 600);
        assert_same(['instagram'], BackgroundTasks::due($now), 'попытка 10 минут назад — ещё рано');

        BackgroundTasks::markAttempt('youtube', $now - 3600);
        assert_same(['youtube', 'instagram'], BackgroundTasks::due($now), 'через час — снова пора');

        // Живой cron ставит Heartbeat — сайт тогда задачу не дублирует.
        file_put_contents(APP_ROOT . '/storage/cache/worker_heartbeat_instagram.txt', (string) ($now - 60));
        assert_same(['youtube'], BackgroundTasks::due($now));
    } finally {
        background_tasks_markers($saved);
    }
});

test('Выключенный импорт не запускается и не выглядит работающим', function (): void {
    $saved = background_tasks_markers();
    try {
        Setting::set('youtube_import_enabled', '0');
        Setting::set('instagram_feed_enabled', '0');
        foreach (['youtube', 'instagram'] as $task) {
            $result = BackgroundTasks::run($task);
            assert_true($result['ran'] === false, $task . ': выключенное не запускается');
            assert_true(BackgroundTasks::lastAttempt($task) !== null, $task . ': попытка отмечена — иначе спрашивали бы каждый запрос');
            assert_same(null, Heartbeat::lastRun($task), $task . ': «Состояние системы» не должно считать его исправным');
        }
        assert_same([], BackgroundTasks::due(), 'после попытки час ничего не делаем');
    } finally {
        background_tasks_markers($saved);
    }
});

test('Новые ролики с YouTube по умолчанию публикуются сразу', function (): void {
    Setting::set('youtube_import_status', '');
    assert_same('published', \App\Core\YoutubeImport::settings()['status']);
    Setting::set('youtube_import_status', 'draft');
    assert_same('draft', \App\Core\YoutubeImport::settings()['status'], 'явный выбор черновика сохраняется');
});
