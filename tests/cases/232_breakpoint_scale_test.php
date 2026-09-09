<?php

declare(strict_types=1);

// Точки перелома публичного CSS сведены к общей шкале: раньше их было 33
// штуки вроде 680/760/820/980/1040/1180, и соседние правила ломались на
// разных ширинах. Тест держит шкалу, иначе разнобой набежит снова.

/** Шкала: max-width и парные к ней min-width (граница + 1). */
const BREAKPOINT_SCALE_MAX = [480, 560, 640, 720, 900, 1000, 1100, 1360];

/** Пара 768/769 зарезервирована под условия показа блоков, см. ниже. */
const BREAKPOINT_VISIBILITY_CONTRACT = [768, 769];

/** @return list<string> Публичные CSS-файлы (админка живёт своей жизнью). */
function breakpoint_css_files(): array
{
    $files = [];
    $dir = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT . '/public/assets/css')
    );
    foreach ($dir as $file) {
        $name = $file->getFilename();
        if (!str_ends_with($name, '.css')) {
            continue;
        }
        if (str_contains($name, '.min.') || str_starts_with($name, 'admin')) {
            continue;
        }
        $files[] = $file->getPathname();
    }
    sort($files);

    return $files;
}

test('Точки перелома: публичный CSS использует только значения шкалы', function () {
    $allowed = BREAKPOINT_SCALE_MAX;
    foreach (BREAKPOINT_SCALE_MAX as $max) {
        $allowed[] = $max + 1;
    }
    $allowed = array_merge($allowed, BREAKPOINT_VISIBILITY_CONTRACT);

    $offScale = [];
    foreach (breakpoint_css_files() as $path) {
        $lines = explode("\n", (string) file_get_contents($path));
        foreach ($lines as $i => $line) {
            if (!str_contains($line, '@media')) {
                continue;
            }
            preg_match_all('/@media[^{]*/', $line, $queries);
            foreach ($queries[0] as $query) {
                preg_match_all('/(max|min)-width:\s*(\d+)px/', $query, $m, PREG_SET_ORDER);
                foreach ($m as $hit) {
                    if (!in_array((int) $hit[2], $allowed, true)) {
                        $offScale[] = basename($path) . ':' . ($i + 1) . ' ' . $hit[1] . '-width:' . $hit[2] . 'px';
                    }
                }
            }
        }
    }

    assert_same([], $offScale, 'значения вне шкалы: ' . implode(', ', $offScale));
});

test('Точки перелома: 768/769 остаются контрактом условий показа блоков', function () {
    $uses = [];
    foreach (breakpoint_css_files() as $path) {
        $lines = explode("\n", (string) file_get_contents($path));
        foreach ($lines as $i => $line) {
            if (!str_contains($line, '@media')) {
                continue;
            }
            if (!preg_match('/(max|min)-width:\s*(768|769)px/', $line)) {
                continue;
            }
            $uses[] = [basename($path) . ':' . ($i + 1), $line];
        }
    }

    // Пара определяет, кому блок виден (BlockVisibility), а не как он выглядит:
    // сдвиг границы поменял бы состав аудитории, поэтому других правил тут быть
    // не должно — обычное оформление обязано жить на шкале.
    assert_same(2, count($uses), 'вхождений пары 768/769: ' . count($uses));
    foreach ($uses as [$where, $line]) {
        assert_contains('.cms-block--only-', $line, 'не контракт: ' . $where);
    }
});
