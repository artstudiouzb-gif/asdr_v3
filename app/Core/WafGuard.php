<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight request-signature guard for obviously malicious traffic.
 * Проверяет входные параметры запроса на сигнатуры SQLi, XSS и Path Traversal.
 */
final class WafGuard
{
    private const EXPLOIT_PATTERNS = [
        '/\b(union\s+select|select\s+.*\s+from\s+information_schema|benchmark\s*\(|pg_sleep\s*\()/i',
        '/<script\b[^>]*>.*?<\/script>/is',
        '/\b(javascript:|vbscript:|data:text\/html|onerror\s*=|onload\s*=)/i',
        '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\/|\.\.%2f|%2e%2e%5c|\0)/i',
        '/\b(etc\/passwd|proc\/self\/environ|boot\.ini|windows\/system32)/i',
    ];

    public static function inspect(): void
    {
        // Пропускаем CLI-запросы и установку
        if (php_sapi_name() === 'cli' || !APP_INSTALLED) {
            return;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $rawPost = file_get_contents('php://input') ?: '';

        $inputs = [$requestUri, $queryString];
        if (strlen($rawPost) < 100000) { // Не сканируем огромные бинарные файлы
            $inputs[] = $rawPost;
        }

        foreach ($inputs as $input) {
            if (!is_string($input) || $input === '') {
                continue;
            }
            foreach (self::EXPLOIT_PATTERNS as $pattern) {
                if (preg_match($pattern, $input)) {
                    self::blockAndLog('WAF: Обнаружена попытка внедрения вредоносного кода');
                }
            }
        }
    }

    private static function blockAndLog(string $reason): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        Logger::warning("{$reason} | IP: {$ip} | URI: {$uri}");

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>403 Access Denied</title><link rel="stylesheet" href="/assets/css/system.css"></head><body class="waf-error"><div class="waf-error__box"><h1>403 Forbidden</h1><p>Запрос заблокирован WAF-системой безопасности.</p><p class="waf-error__ip">IP: ' . htmlspecialchars($ip, ENT_QUOTES) . '</p></div></body></html>';
        exit;
    }
}
