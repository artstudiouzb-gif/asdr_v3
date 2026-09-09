<?php

declare(strict_types=1);

namespace App\Core;

use ZipArchive;

/**
 * Обновление кода сайта из релиза на GitHub.
 *
 * Раньше выкладка была ручной: скачать ZIP из Releases, сверить контрольную
 * сумму, залить по FTP, накатить миграции. Каждый шаг можно пропустить, и
 * пропускали обычно сверку суммы — самый тихий из отказов.
 *
 * **Устанавливаем только собранный архив релиза** (`asdr-cms-*.zip`), а не
 * «Source code» и не ветку. Собранный архив проходит проверку состава в
 * workflow «Release package»: в нём нет тестов, `.github`, `composer.json` и
 * прочего, чему на боевом сервере делать нечего. Релиз без такого архива —
 * отказ с объяснением, а не установка чего попало: неверная форма дерева
 * снесла бы структуру сайта.
 *
 * **Данные сайта не трогаем никогда.** `config/config.php`, `storage/` и
 * `public/uploads/` в архиве отсутствуют и в список замены не попадают —
 * см. `isPreserved()`.
 *
 * **Устаревшие файлы удаляются только из каталогов, которыми владеет релиз**
 * (`app/`, `templates/`, `scripts/`, `database/`, `public/assets/`). Оставить
 * их нельзя: старый обработчик с уязвимостью продолжал бы отвечать. Удалять
 * за пределами этих каталогов — тоже нельзя: там живут данные сайта.
 */
final class Updater
{
    /** Откуда берём релизы. Значение из окружения, а не из настроек в БД:
     *  настройка в панели позволила бы редактору увести обновление на чужой
     *  репозиторий, то есть выполнить свой код на сервере. */
    private const DEFAULT_REPO = 'artstudiouzb-gif/asdr_v2';

    /** Домены GitHub, куда разрешено ходить. Ассеты отдаются с CDN редиректом,
     *  поэтому список шире, чем один api.github.com. */
    private const ALLOWED_HOSTS = [
        'api.github.com',
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    /** Больше этого архив релиза быть не может — защита от «бесконечного» тела. */
    private const MAX_ARCHIVE_BYTES = 64 * 1024 * 1024;

    private const MAX_REDIRECTS = 5;

    private const TIMEOUT = 60;

    /** Каталоги, которыми владеет релиз: там устаревшие файлы удаляются. */
    private const OWNED_DIRS = ['app', 'templates', 'scripts', 'database', 'public/assets'];

    /** Что обязано быть в распакованном дереве, иначе это не наш архив. */
    private const REQUIRED = [
        'app/Core/bootstrap.php',
        'public/index.php',
        'database/schema.sql',
        'config/config.example.php',
    ];

    /** Чего в архиве быть не должно: признак, что подсунули не тот ZIP. */
    private const FORBIDDEN = ['config/config.php', 'storage/installed.lock', 'tests', 'composer.json'];

    public static function repo(): string
    {
        $env = trim((string) getenv('UPDATE_REPO'));

        return preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $env) === 1 ? $env : self::DEFAULT_REPO;
    }

    public static function root(): string
    {
        return defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
    }

    public static function installedVersion(): string
    {
        return Release::id();
    }

    /**
     * Данные последнего релиза с GitHub.
     *
     * @return array{tag:string, name:string, published_at:string, assets:list<array{name:string,url:string,size:int}>}
     * @throws \RuntimeException
     */
    public static function latestRelease(): array
    {
        $url = 'https://api.github.com/repos/' . self::repo() . '/releases/latest';
        $response = self::fetch($url, ['Accept: application/vnd.github+json']);
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['tag_name'])) {
            throw new \RuntimeException('GitHub ответил не тем, чего мы ждали: релиз не разобран.');
        }

        $assets = [];
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (!is_array($asset) || !isset($asset['name'], $asset['browser_download_url'])) {
                continue;
            }
            $assets[] = [
                'name' => (string) $asset['name'],
                'url' => (string) $asset['browser_download_url'],
                'size' => (int) ($asset['size'] ?? 0),
            ];
        }

        return [
            'tag' => (string) $data['tag_name'],
            'name' => (string) ($data['name'] ?? $data['tag_name']),
            'published_at' => (string) ($data['published_at'] ?? ''),
            'assets' => $assets,
        ];
    }

    /**
     * Выбирает установочный архив и файл контрольной суммы к нему.
     *
     * @param list<array{name:string,url:string,size:int}> $assets
     * @return array{archive:array{name:string,url:string,size:int}, checksum:array{name:string,url:string,size:int}}|null
     */
    public static function pickAsset(array $assets): ?array
    {
        $archive = null;
        foreach ($assets as $asset) {
            if (preg_match('/^asdr-cms-.+\.zip$/', $asset['name']) === 1) {
                $archive = $asset;
                break;
            }
        }
        if ($archive === null) {
            return null;
        }

        foreach ($assets as $asset) {
            if ($asset['name'] === $archive['name'] . '.sha256') {
                return ['archive' => $archive, 'checksum' => $asset];
            }
        }

        // Архив без суммы не ставим: сверять целостность будет нечем.
        return null;
    }

    /** Состояние обновления: что стоит, что доступно, можно ли ставить. */
    public static function check(): array
    {
        $installed = self::installedVersion();
        try {
            $release = self::latestRelease();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'installed' => $installed,
                'error' => $e->getMessage(),
            ];
        }

        $asset = self::pickAsset($release['assets']);

        return [
            'ok' => true,
            'installed' => $installed,
            'latest' => $release['tag'],
            'published_at' => $release['published_at'],
            'available' => $release['tag'] !== $installed,
            'installable' => $asset !== null,
            'asset' => $asset,
            'reason' => $asset === null
                ? 'У релиза нет установочного архива asdr-cms-*.zip вместе с .sha256 — соберите его workflow «Release package».'
                : '',
        ];
    }

    /** Скачивает файл по адресу GitHub в указанный путь. */
    public static function download(string $url, string $destination): int
    {
        $body = self::fetch($url, ['Accept: application/octet-stream']);
        if (file_put_contents($destination, $body, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось сохранить загруженный файл: ' . $destination);
        }

        return strlen($body);
    }

    /**
     * Сверяет файл со строкой из `.sha256` (формат `<hash>  <имя>`).
     * Это защита от битой загрузки, а не подпись: и архив, и сумму отдаёт
     * один и тот же GitHub. Настоящая гарантия здесь — TLS и сам GitHub.
     */
    public static function verifyChecksum(string $file, string $checksumLine): bool
    {
        if (preg_match('/\b([0-9a-f]{64})\b/i', $checksumLine, $m) !== 1) {
            return false;
        }
        $actual = hash_file('sha256', $file);

        return is_string($actual) && hash_equals(strtolower($m[1]), $actual);
    }

    /** Путь принадлежит данным сайта и обновлением не затрагивается. */
    public static function isPreserved(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        foreach (['config/config.php', 'storage', 'public/uploads'] as $keep) {
            if ($relative === $keep || str_starts_with($relative, $keep . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверяет распакованное дерево: это точно наш установочный архив?
     *
     * @return list<string> список претензий; пустой — дерево годное
     */
    public static function validateTree(string $dir): array
    {
        $problems = [];
        foreach (self::REQUIRED as $required) {
            if (!is_file($dir . '/' . $required)) {
                $problems[] = 'в архиве нет обязательного файла ' . $required;
            }
        }
        foreach (self::FORBIDDEN as $forbidden) {
            if (file_exists($dir . '/' . $forbidden)) {
                $problems[] = 'в архиве есть лишнее: ' . $forbidden;
            }
        }

        return $problems;
    }

    /**
     * Что предстоит сделать: какие файлы заменить и какие устаревшие удалить.
     *
     * @return array{copy:list<string>, delete:list<string>}
     */
    public static function plan(string $newTree, ?string $root = null): array
    {
        $root = $root ?? self::root();
        $incoming = self::listFiles($newTree);

        $copy = [];
        foreach ($incoming as $relative) {
            if (self::isPreserved($relative)) {
                continue;
            }
            $copy[] = $relative;
        }

        $known = array_fill_keys($incoming, true);
        $delete = [];
        foreach (self::OWNED_DIRS as $owned) {
            $dir = $root . '/' . $owned;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (self::listFiles($dir, $owned) as $relative) {
                if (isset($known[$relative]) || self::isPreserved($relative)) {
                    continue;
                }
                $delete[] = $relative;
            }
        }

        sort($copy);
        sort($delete);

        return ['copy' => $copy, 'delete' => $delete];
    }

    /**
     * Раскладывает файлы по местам. Возвращает число заменённых и удалённых.
     *
     * @param array{copy:list<string>, delete:list<string>} $plan
     * @return array{copied:int, deleted:int}
     */
    public static function apply(string $newTree, array $plan, ?string $root = null): array
    {
        $root = $root ?? self::root();
        $copied = 0;
        foreach ($plan['copy'] as $relative) {
            $target = $root . '/' . $relative;
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Не удалось создать каталог: ' . $dir);
            }
            if (!@copy($newTree . '/' . $relative, $target)) {
                throw new \RuntimeException('Не удалось записать файл: ' . $relative);
            }
            // Файл, собранный PHP, получает права по umask, и веб-сервер может
            // его не прочитать — те же грабли, что у загрузок в медиатеку.
            @chmod($target, 0644);
            $copied++;
        }

        $deleted = 0;
        foreach ($plan['delete'] as $relative) {
            if (@unlink($root . '/' . $relative)) {
                $deleted++;
            }
        }

        return ['copied' => $copied, 'deleted' => $deleted];
    }

    /** Распаковывает архив в каталог. */
    public static function extract(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Архив не открывается: ' . $zipPath);
        }
        try {
            // Архив собран `git archive --prefix=asdr-cms/`, поэтому имена
            // внутри начинаются с этого каталога. Заодно отсекаем выход за
            // пределы каталога назначения.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_contains($name, '..')) {
                    throw new \RuntimeException('Небезопасное имя в архиве: ' . $name);
                }
            }
            if (!$zip->extractTo($destination)) {
                throw new \RuntimeException('Не удалось распаковать архив.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Все файлы каталога относительными путями.
     *
     * @return list<string>
     */
    public static function listFiles(string $dir, string $prefix = ''): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $base = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $pathname = str_replace('\\', '/', $item->getPathname());
            $relative = substr($pathname, strlen($base));
            $files[] = $prefix === '' ? $relative : rtrim($prefix, '/') . '/' . ltrim($relative, '/');
        }
        sort($files);

        return $files;
    }

    /** Адрес ведёт на GitHub и безопасен для серверного запроса. */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        return in_array(strtolower((string) $parts['host']), self::ALLOWED_HOSTS, true);
    }

    /**
     * Запрос к GitHub. Редиректы проходим вручную и проверяем каждый шаг:
     * cURL с `FOLLOWLOCATION` увёл бы нас на любой хост, а ассеты релиза
     * отдаются редиректом на CDN — без обработки редиректа их не скачать.
     *
     * @param list<string> $headers
     */
    private static function fetch(string $url, array $headers = []): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Для обновления нужен модуль cURL.');
        }

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::isAllowedUrl($url)) {
                throw new \RuntimeException('Адрес не принадлежит GitHub: ' . $url);
            }
            $target = UrlGuard::safeRemoteTarget($url);
            if ($target === null) {
                throw new \RuntimeException('Адрес отклонён проверкой безопасности: ' . $url);
            }

            $body = '';
            $tooLarge = false;
            $responseHeaders = [];
            $addresses = array_map(
                static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
                $target['ips']
            );

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . implode(',', $addresses)],
                CURLOPT_USERAGENT => 'ArtStudio-CMS-Updater',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $responseHeaders[] = $line;
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_ARCHIVE_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = $ok === false ? (string) curl_error($ch) : '';
            // curl_close() устарел в PHP 8.5: дескриптор освобождается сам.
            unset($ch);

            if ($tooLarge) {
                throw new \RuntimeException('Ответ GitHub больше допустимого размера.');
            }
            if ($error !== '') {
                throw new \RuntimeException('Запрос к GitHub не удался: ' . $error);
            }

            if ($status >= 300 && $status < 400) {
                $location = '';
                foreach ($responseHeaders as $line) {
                    if (stripos($line, 'location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                }
                if ($location === '') {
                    throw new \RuntimeException('GitHub ответил редиректом без адреса.');
                }
                $url = $location;
                continue;
            }

            if ($status !== 200) {
                throw new \RuntimeException('GitHub ответил ' . $status . '.');
            }

            return $body;
        }

        throw new \RuntimeException('Слишком много редиректов при обращении к GitHub.');
    }
}
