<?php

declare(strict_types=1);

/**
 * Ссылка в foreach обязана сниматься unset() сразу после цикла.
 *
 * Переменная остаётся псевдонимом последнего элемента, и следующее же
 * присваивание в неё молча портит массив — классическая мина PHP, которую
 * не видит ни линтер, ни PHPStan. Пока цикл последний в методе, вреда нет,
 * и потому четыре таких места жили в проекте годами; срабатывает мина при
 * первой правке, когда после цикла добавляют строку.
 *
 * Сторож держит приём целиком, а не считает оставшиеся места: пропуск здесь
 * незачем добавлять никогда, значит потолок — ноль, а не «по факту».
 */
test('Ссылка в foreach снимается unset() сразу после цикла', function (): void {
    $offenders = [];

    foreach (['app', 'templates'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $lines = file($path) ?: [];
            foreach ($lines as $i => $line) {
                if (preg_match('/foreach\s*\([^)]*\bas\s*&\s*\$(\w+)/', $line, $m) !== 1) {
                    continue;
                }

                // Цикл может быть в несколько строк: ищем unset($var) в
                // пределах его тела с запасом, а не строго на следующей строке.
                $tail = implode('', array_slice($lines, $i, 60));
                if (!str_contains($tail, 'unset($' . $m[1] . ')')) {
                    $offenders[] = sprintf(
                        '%s:%d — foreach … as &$%s без unset($%s)',
                        str_replace(APP_ROOT . '/', '', $path),
                        $i + 1,
                        $m[1],
                        $m[1]
                    );
                }
            }
        }
    }

    assert_same([], $offenders, 'ссылка переживает цикл и портит массив при следующем присваивании');
});
