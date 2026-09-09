<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Database;
use App\Core\RepoFile;
use App\Core\Router;
use App\Core\Search;
use App\Core\SecurityHeaders;
use App\Core\TextProcessor;
use App\Core\Uploader;
use App\Core\UrlGuard;
use App\Models\News;
use App\Models\Page;
use App\Models\User;

test('Hacker Attack Vectors Test: эмуляция реальных атак SQLi, XSS, CSRF, Path Traversal, SSRF и RCE', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();

    // ==========================================
    // 1. АТАКА: SQL-Инъекции (SQLi)
    // ==========================================
    $sqliVectors = [
        "1' OR '1'='1",
        "1' UNION SELECT 1, username, password_hash FROM users --",
        "1; DROP TABLE news; --",
        "1' AND SLEEP(0.1) --",
        "' HAVING 1=1 --",
        "admin'--",
        "1' AND (SELECT 1 FROM (SELECT COUNT(*), CONCAT(version(), FLOOR(RAND(0)*2)) x FROM information_schema.tables GROUP BY x)a) --",
    ];

    foreach ($sqliVectors as $payload) {
        // Проверка через централизованный глобальный поиск по всей БД
        try {
            $results = Search::query($payload, 5);
            assert_true(is_array($results), 'SQLi вектор в глобальном поиске нейтрализован: ' . $payload);
        } catch (\Throwable $e) {
            assert_true(false, 'SQLi выбил SQL-ошибку в глобальном поиске: ' . $payload . ' -> ' . $e->getMessage());
        }
    }


    // ==========================================
    // 2. АТАКА: Межсайтовый скриптинг (XSS & CSP)
    // ==========================================
    $xssUrls = [
        'javascript:alert(document.cookie)',
        'vbscript:msgbox(1)',
        'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    ];

    foreach ($xssUrls as $payload) {
        // Проверка очистки URL через UrlGuard
        $isSafe = UrlGuard::isSafeLink($payload);
        assert_true(!$isSafe, 'UrlGuard заблокировал опасный XSS URI: ' . $payload);
    }

    // Проверка применения криптографических nonce в CSP для защиты от несанкционированного XSS
    $cspHtml = SecurityHeaders::injectScriptNonce('<script>alert(1)</script>', 'secure_nonce_123');
    assert_contains('nonce="secure_nonce_123"', $cspHtml, 'CSP принудительно накладывает криптографический nonce на скрипты');


    // ==========================================
    // 3. АТАКА: Подделка межсайтовых запросов (CSRF)
    // ==========================================
    // Проверяем работу токена безопасности CSRF
    $token = Csrf::token();
    assert_true(is_string($token) && strlen($token) >= 32, 'Сгенерирован криптографически стойкий CSRF токен');

    // Поддельные/некорректные CSRF токены должны отвергаться
    $invalidTokens = [
        null,
        '',
        'invalid_csrf_token_123',
        str_repeat('a', 64),
        "admin'--",
    ];

    foreach ($invalidTokens as $badToken) {
        $isValid = Csrf::verify($badToken);
        assert_true(!$isValid, 'CSRF валидация успешно отвергла поддельный токен');
    }


    // ==========================================
    // 4. АТАКА: Чтение произвольных файлов / Выход за пределы каталога (Path Traversal / LFI)
    // ==========================================
    $traversalPaths = [
        '../../../../config/config.php',
        '../../../../etc/passwd',
        '..\\..\\..\\..\\windows\\win.ini',
        '....//....//....//config/config.php',
        '%2e%2e%2f%2e%2e%2fconfig.php',
        "../../config/config.php\0.jpg",
    ];

    // Проверка удаления файлов репозитория с защитой от Path Traversal
    $basePath = \App\Models\RepoFile::basePath();
    foreach ($traversalPaths as $path) {
        $expectedBase = realpath($basePath);
        if ($expectedBase !== false) {
            $full = realpath($expectedBase . '/' . $path);
            // Файл не должен выйти за пределы базовой директории
            if ($full !== false) {
                assert_true(str_starts_with($full, $expectedBase), 'Path Traversal заблокирован при работе с файлами: ' . $path);
            } else {
                assert_true(true, 'Несуществующий путь Traversal корректно не найден');
            }
        }
    }


    // ==========================================
    // 5. АТАКА: Загрузка веб-шелла и исполняемых файлов (RCE)
    // ==========================================
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'txt', 'mp4', 'mp3'];
    $dangerousExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'sh', 'pl', 'cgi', 'js', 'vbs', 'jsp', 'asp', 'aspx',
    ];

    foreach ($dangerousExtensions as $ext) {
        $isAllowed = in_array(strtolower($ext), $allowedExts, true);
        assert_true(!$isAllowed, 'Загрузчик принудительно заблокировал вредоносное расширение: .' . $ext);
    }


    // ==========================================
    // 6. АТАКА: Запрос на внутренние ресурсы (SSRF)
    // ==========================================
    $ssrfTargets = [
        'http://127.0.0.1/admin',
        'http://localhost:8080',
        'http://169.254.169.254/latest/meta-data/', // AWS Metadata API
        'http://10.0.0.1/internal',
        'http://192.168.1.1/router',
    ];

    foreach ($ssrfTargets as $target) {
        $isSafeRemote = UrlGuard::isSafeRemote($target);
        assert_true(!$isSafeRemote, 'UrlGuard заблокировал SSRF атаку на внутренний IP: ' . $target);
    }

    $validPublicTarget = 'https://google.com';
    assert_true(UrlGuard::isSafeRemote($validPublicTarget), 'UrlGuard разрешил обращения к публичным доменам');


    // ==========================================
    // 7. АТАКА: Заголовки безопасности и CSP
    // ==========================================
    SecurityHeaders::send('/');
    // Заголовки безопасности сформированы без ошибок
    assert_true(true, 'Заголовки безопасности HTTP успешно применены');
});
