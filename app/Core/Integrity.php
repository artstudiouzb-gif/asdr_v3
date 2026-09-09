<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Контроль целостности файлов: эталон SHA-256 и сверка с ним.
 *
 * Отслеживается только то, что между релизами меняться не должно — код,
 * шаблоны, миграции, статика темы. Всё, что живёт своей жизнью (загрузки,
 * кэш, логи, бэкапы, сгенерированные CSS/JS страниц), исключено: иначе
 * отчёт превращается в список обычной работы сайта, и в нём тонет веб-шелл.
 */
final class Integrity
{
    /** Каталоги, попадающие в эталон (относительно корня проекта). */
    private const ROOTS = ['app', 'config', 'database', 'public', 'scripts', 'templates'];

    /** Файлы в корне проекта, которые тоже отслеживаются. */
    private const ROOT_FILES = ['.htaccess', 'composer.json', 'package.json'];

    /**
     * Пути-исключения (префиксы относительно корня). Меняются в обычной
     * работе сайта и к компрометации отношения не имеют.
     */
    private const SKIP = [
        'public/uploads/',
        'public/assets/vendor/',
        'storage/',
        'node_modules/',
        'vendor/',
        '.git/',
    ];

    private const BASELINE = 'storage/integrity/baseline.json';
    private const REPORTED = 'storage/integrity/reported.json';

    /** Подмена корня — только для тестов, чтобы не трогать боевой эталон. */
    private static ?string $rootOverride = null;

    public static function useRoot(?string $root): void
    {
        self::$rootOverride = $root === null ? null : rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Снимает эталон. Возвращает число файлов в нём.
     */
    public static function writeBaseline(): int
    {
        $hashes = self::scan();
        self::writeJson(self::path(self::BASELINE), [
            'created_at' => date('c'),
            'files' => $hashes,
        ]);
        self::rememberReported([]);

        return count($hashes);
    }

    public static function hasBaseline(): bool
    {
        return is_file(self::path(self::BASELINE));
    }

    /**
     * Сверяет текущее состояние с эталоном.
     *
     * @return array{added:list<string>, changed:list<string>, removed:list<string>}
     */
    public static function compare(): array
    {
        // Битый или обрезанный эталон (диск кончился при записи) не должен
        // ронять почасовой cron: считаем, что эталона нет, и всё окажется
        // «новым» — заметно, но безопасно.
        $stored = self::readJson(self::path(self::BASELINE))['files'] ?? [];
        $baseline = is_array($stored) ? $stored : [];
        $current = self::scan();

        $added = [];
        $changed = [];
        foreach ($current as $relative => $hash) {
            if (!isset($baseline[$relative])) {
                $added[] = $relative;
            } elseif (!hash_equals((string) $baseline[$relative], $hash)) {
                $changed[] = $relative;
            }
        }
        $removed = array_values(array_diff(array_keys($baseline), array_keys($current)));

        sort($added);
        sort($changed);
        sort($removed);

        return ['added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    /**
     * Новый ли это набор расхождений по сравнению с прошлым запуском.
     *
     * @param array{added:list<string>, changed:list<string>, removed:list<string>} $diff
     */
    public static function isNewReport(array $diff): bool
    {
        $stored = self::readJson(self::path(self::REPORTED))['fingerprint'] ?? '';
        $previous = is_string($stored) ? $stored : '';

        return $previous !== self::fingerprint($diff);
    }

    /**
     * @param array{added?:list<string>, changed?:list<string>, removed?:list<string>} $diff
     */
    public static function rememberReported(array $diff): void
    {
        self::writeJson(self::path(self::REPORTED), [
            'reported_at' => date('c'),
            'fingerprint' => self::fingerprint($diff),
        ]);
    }

    /**
     * @param array{added:list<string>, changed:list<string>, removed:list<string>} $diff
     */
    public static function alertText(array $diff): string
    {
        $lines = ['<b>Файлы сайта изменились без релиза</b>', ''];
        foreach (['changed' => 'Изменены', 'added' => 'Появились', 'removed' => 'Удалены'] as $key => $title) {
            if ($diff[$key] === []) {
                continue;
            }
            $lines[] = $title . ' (' . count($diff[$key]) . '):';
            foreach (array_slice($diff[$key], 0, 10) as $path) {
                $lines[] = '• ' . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            if (count($diff[$key]) > 10) {
                $lines[] = '… и ещё ' . (count($diff[$key]) - 10);
            }
            $lines[] = '';
        }
        $lines[] = 'Если обновление плановое — снимите эталон заново:';
        $lines[] = 'php app/Console/integrity_check.php --baseline';

        return implode("\n", $lines);
    }

    /**
     * Возраст эталона в секундах или null, если эталона нет.
     */
    public static function baselineAge(): ?int
    {
        $time = @filemtime(self::path(self::BASELINE));

        return $time === false ? null : max(0, time() - $time);
    }

    /**
     * @return array<string,string> относительный путь => sha256
     */
    private static function scan(): array
    {
        $root = self::root();
        $hashes = [];

        foreach (self::ROOT_FILES as $name) {
            $path = $root . '/' . $name;
            if (is_file($path)) {
                $hashes[$name] = (string) hash_file('sha256', $path);
            }
        }

        foreach (self::ROOTS as $directory) {
            $base = $root . '/' . $directory;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $info) use ($root): bool {
                        return !self::skipped(self::relative($root, $info->getPathname()) . ($info->isDir() ? '/' : ''));
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = self::relative($root, $file->getPathname());
                $hashes[$relative] = (string) hash_file('sha256', $file->getPathname());
            }
        }

        ksort($hashes);

        return $hashes;
    }

    private static function skipped(string $relative): bool
    {
        foreach (self::SKIP as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function relative(string $root, string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    }

    /**
     * @param array{added?:list<string>, changed?:list<string>, removed?:list<string>} $diff
     */
    private static function fingerprint(array $diff): string
    {
        $payload = [
            'added' => $diff['added'] ?? [],
            'changed' => $diff['changed'] ?? [],
            'removed' => $diff['removed'] ?? [],
        ];
        if ($payload['added'] === [] && $payload['changed'] === [] && $payload['removed'] === []) {
            return '';
        }

        return hash('sha256', (string) json_encode($payload));
    }

    private static function path(string $relative): string
    {
        return self::root() . '/' . $relative;
    }

    private static function root(): string
    {
        if (self::$rootOverride !== null) {
            return self::$rootOverride;
        }

        return defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $temporary = $path . '.tmp';
        $payload = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);

            return;
        }
        @chmod($path, 0640);
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
