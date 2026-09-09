<?php

declare(strict_types=1);

use App\Core\ErrorHandler;

/*
 * Совместимость с PHP 8.5. Хостинг обновляет версию без спроса, а сайт до
 * этого падал целиком: обработчик превращал любое уведомление в исключение,
 * и первый же deprecated (curl_close, imagedestroy, $http_response_header,
 * драйверные константы PDO) отдавал 500 вместо страницы. Здесь два сторожа:
 * уведомление не должно валить запрос, а устаревший вызов — не должен
 * появляться в коде заново.
 */

/**
 * Файлы проекта без комментариев: упоминание устаревшего вызова в пояснении
 * (а они есть — тут же, в этом файле) не должно считаться его использованием.
 *
 * @return array<string,string> путь относительно корня => исходник
 */
function php85_project_sources(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach (['app', 'public', 'scripts', 'templates', 'database', 'tests', 'config'] as $dir) {
        $root = APP_ROOT . '/' . $dir;
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $normalizedPath = str_replace('\\', '/', $path);
            if (realpath($path) === realpath(__FILE__) || str_contains($normalizedPath, '/vendor/') || str_contains($normalizedPath, '/node_modules/')) {
                continue;
            }
            $normalizedRoot = str_replace('\\', '/', APP_ROOT);
            $cache[str_replace($normalizedRoot . '/', '', $normalizedPath)] = php_strip_whitespace($path);
        }
    }
    ksort($cache);

    return $cache;
}

test('В коде нет вызовов, устаревших в PHP 8.5', function (): void {
    $rules = [
        '/\bcurl_close\s*\(/' => 'curl_close(): с PHP 8.0 дескриптор — объект и освобождается сам',
        '/\bcurl_share_close\s*\(/' => 'curl_share_close(): CurlShareHandle освобождается сам',
        '/\bimagedestroy\s*\(/' => 'imagedestroy(): GdImage освобождается сборщиком — достаточно unset()',
        '/\bfinfo_close\s*\(/' => 'finfo_close(): объект finfo освобождается сам',
        '/\bxml_parser_free\s*\(/' => 'xml_parser_free(): XMLParser освобождается сам',
        '/\$http_response_header\b/' => '$http_response_header: код ответа берётся из stream_get_meta_data()',
        '/\bPDO::(MYSQL|PGSQL|SQLITE|OCI|FB|SQLSRV)_[A-Z]/' => 'драйверная константа PDO — подкласс драйвера (Pdo\Mysql::ATTR_*)',
        '/->setAccessible\s*\(/' => 'Reflection::setAccessible(): не нужен с PHP 8.1',
        '/\(\s*(integer|boolean|double|binary)\s*\)\s*[\$\(]/' => 'неканоническое имя приведения — (int)/(bool)/(float)/(string)',
        '/\bMHASH_[A-Z]/' => 'константы MHASH_* устарели — hash_hmac()',
        '/\bsocket_set_timeout\s*\(/' => 'socket_set_timeout(): псевдоним устарел — stream_set_timeout()',
        '/\bmysqli_execute\s*\(/' => 'mysqli_execute(): псевдоним устарел — mysqli_stmt_execute()',
        '/\b(readdir|rewinddir|closedir)\s*\(\s*(null\s*)?\)/' => 'каталог передаётся явным ресурсом',
        '/\bDATE_RFC7231\b|DateTimeInterface::RFC7231/' => 'константа RFC7231 устарела',
        '/\bget_defined_functions\s*\(\s*[^)\s]/' => 'параметр $exclude_disabled устарел',
        '/spl_autoload_unregister\s*\(\s*[\'"]spl_autoload_call/' => 'снятие всех автозагрузчиков устарело',
    ];

    $offenders = [];
    foreach (php85_project_sources() as $path => $source) {
        foreach ($rules as $pattern => $why) {
            if (preg_match($pattern, $source) === 1) {
                $offenders[] = $path . ' — ' . $why;
            }
        }
    }

    assert_same([], $offenders, "устаревшее в PHP 8.5:\n      " . implode("\n      ", $offenders));
});

test('Уведомление об устаревшем API пишется в журнал, а не рвёт запрос', function (): void {
    $level = error_reporting();
    error_reporting(E_ALL);
    $logPath = APP_ROOT . '/storage/logs/error.log';
    $before = is_file($logPath) ? (int) filesize($logPath) : 0;
    $marker = 'проба устаревшего вызова ' . bin2hex(random_bytes(4));

    try {
        assert_true(
            ErrorHandler::handleError(E_DEPRECATED, $marker, __FILE__, __LINE__),
            'E_DEPRECATED обязан обрабатываться без исключения'
        );
        assert_true(
            ErrorHandler::handleError(E_USER_DEPRECATED, $marker . ' (user)', __FILE__, __LINE__),
            'E_USER_DEPRECATED обязан обрабатываться без исключения'
        );

        // Остальные ошибки по-прежнему становятся исключением: молчаливое
        // предупреждение — это потерянные данные, а не смена версии PHP.
        $threw = false;
        try {
            ErrorHandler::handleError(E_WARNING, 'проба предупреждения', __FILE__, __LINE__);
        } catch (\ErrorException $e) {
            $threw = true;
        }
        assert_true($threw, 'E_WARNING обязан оставаться исключением');
    } finally {
        error_reporting($level);
    }

    $written = is_file($logPath) ? (string) file_get_contents($logPath, false, null, $before) : '';
    assert_contains('DEPRECATED', $written, 'уведомление должно попадать в журнал');
    assert_contains($marker, $written, 'в журнале должно быть само сообщение');
});

test('Драйверные атрибуты PDO берутся из подкласса драйвера', function (): void {
    // Минимум проекта — PHP 8.4, где у драйверов появились свои подклассы.
    // Прежние константы `PDO::MYSQL_ATTR_*` в 8.5 объявлены устаревшими, и
    // обращение к ним печатало бы уведомление при каждом соединении с базой.
    assert_true(
        defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY'),
        'подкласс Pdo\\Mysql обязан быть доступен — минимум проекта PHP 8.4'
    );
    assert_true(
        \Pdo\Mysql::ATTR_MULTI_STATEMENTS > 0,
        'MULTI_STATEMENTS обязан разрешаться'
    );
});
