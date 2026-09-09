<?php

declare(strict_types=1);

test('release automation is fail-fast and includes backup, migrations and smoke check', function (): void {
    $root = dirname(__DIR__, 2);
    $release = (string) file_get_contents($root . '/scripts/release.php');
    $check = (string) file_get_contents($root . '/scripts/release_check.php');

    assert_contains('backup_worker.php', $release);
    assert_contains('database/migrate.php', $release);
    assert_contains('scripts/smoke.php', $release);
    assert_contains('exit($code)', $release);
    assert_contains("hash_file('sha256'", $release);
    assert_contains('RecursiveDirectoryIterator', $release);
    assert_contains("extension_loaded", $check);
    assert_contains("SELECT filename FROM migrations", $check);
    assert_contains("APP_DEBUG включён", $check);
});

test('release archive excludes repository-only security documentation', function (): void {
    $attributes = (string) file_get_contents(dirname(__DIR__, 2) . '/.gitattributes');

    assert_contains('/README.md export-ignore', $attributes);
    assert_contains('/SECURITY.md export-ignore', $attributes);
});

test('root-level development files never reach the release archive', function (): void {
    // Список export-ignore перечисляет файлы поимённо, и каждый новый инструмент
    // разработки приходится вписывать туда руками. Забыть строку легко и тихо:
    // так в пакет 3.0 уехал phpstan-baseline.neon (172 КБ). Поэтому правило
    // перевёрнуто — в корне репозитория релизу принадлежат только те файлы,
    // которые перечислены здесь; всё остальное обязано быть исключено.
    $root = dirname(__DIR__, 2);
    if (!is_dir($root . '/.git')) {
        skip_test('нужна рабочая копия git');
    }

    $runtime = ['.htaccess', 'preload.php'];

    $tracked = [];
    exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $tracked, $code);
    if ($code !== 0 || $tracked === []) {
        skip_test('git ls-files недоступен');
    }

    $rootFiles = [];
    foreach ($tracked as $path) {
        if (!str_contains($path, '/')) {
            $rootFiles[] = $path;
        }
    }
    assert_true(count($rootFiles) > 5, 'список файлов корня подозрительно короткий');

    $attributes = (string) file_get_contents($root . '/.gitattributes');
    foreach ($rootFiles as $file) {
        if (in_array($file, $runtime, true)) {
            continue;
        }
        assert_contains(
            '/' . $file . ' export-ignore',
            $attributes,
            "файл {$file} уедет в релизный архив: добавьте строку в .gitattributes"
            . ' или в список runtime, если он действительно нужен на боевом сервере'
        );
    }
});
