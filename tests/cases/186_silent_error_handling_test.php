<?php

declare(strict_types=1);

use App\Core\Logger;

test('Ошибки не глушатся молча: пустых перехватов Throwable в app/ нет', function (): void {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT . '/app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());

        // Пустое тело перехвата: код продолжает выполнение, но следов не
        // оставляет, и перекошенные данные потом нечем объяснить.
        //
        // В скобках требуем «\» или «$» — так правило попадает только в PHP
        // (`catch (\Throwable)`, `catch (Throwable $e)`) и не задевает
        // JavaScript во вьюхах, где `catch (e) {}` вокруг localStorage —
        // нормальная защита от отключённого хранилища.
        if (preg_match('/catch\s*\(\s*[^)]*[\\\\$][^)]*\)\s*\{\s*\}/', $source) === 1) {
            $offenders[] = str_replace(APP_ROOT . '/', '', $file->getPathname());
        }
    }

    sort($offenders);
    assert_same(
        [],
        $offenders,
        'глушат ошибку без записи в лог: ' . implode(', ', $offenders)
            . '. Используйте Logger::swallowed() или пробросьте исключение'
    );
});

test('Logger::swallowed пишет предупреждение и схлопывает повторы', function (): void {
    // WARNING уходит в отдельный канал warning (см. Logger::LEVEL_CHANNEL).
    $logPath = APP_ROOT . '/storage/logs/warning.log';
    $before = is_file($logPath) ? (int) filesize($logPath) : 0;

    $marker = 'проба глушения ' . bin2hex(random_bytes(4));
    $exception = new \RuntimeException('подробность сбоя');

    Logger::swallowed($marker, $exception);
    Logger::swallowed($marker, $exception); // тот же повод — второй строки быть не должно

    $written = is_file($logPath)
        ? (string) file_get_contents($logPath, false, null, $before)
        : '';

    assert_same(1, substr_count($written, $marker), 'повтор того же сбоя не дублируется в логе');
    assert_contains('WARNING', $written, 'уровень записи — предупреждение');
    assert_contains('подробность сбоя', $written, 'сообщение исключения попадает в лог');
});
