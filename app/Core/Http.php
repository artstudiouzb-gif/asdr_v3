<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Минимальный HTTP-клиент на нативном cURL (расширение ext-curl, не Composer)
 * с fallback на потоковый контекст. Используется для запросов к API соцсетей.
 * TLS-проверка сертификата включена жёстко.
 *
 * @phpstan-type HttpResponse array{status:int, body:string, error:string}
 */
final class Http
{
    private const SAFE_REMOTE_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @param array<int, string> $headers строки заголовков вида "Name: value"
     * @return array{status:int, body:string, error:string}
     */
    public static function request(string $method, string $url, string $body = '', array $headers = [], int $timeout = 20): array
    {
        if (function_exists('curl_init')) {
            return self::viaCurl($method, $url, $body, $headers, $timeout);
        }

        return self::viaStream($method, $url, $body, $headers, $timeout);
    }

    /** @param array<int, string> $headers */
    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, '', $headers, $timeout);
    }

    /** @param array<int, string> $headers */
    public static function postForm(string $url, array $fields, array $headers = [], int $timeout = 20): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return self::request('POST', $url, http_build_query($fields), $headers, $timeout);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 20): array
    {
        $headers[] = 'Content-Type: application/json';
        return self::request('POST', $url, (string) json_encode($payload, JSON_UNESCAPED_UNICODE), $headers, $timeout);
    }

    /**
     * Запрос по URL, который может задаваться пользователем. В отличие от
     * request(), проверяет публичность всех DNS-адресов, закрепляет их в cURL
     * и сверяет фактический IP соединения. Без cURL работает fail-closed.
     *
     * @param array<int, string> $headers
     * @return array{status:int, body:string, error:string}
     */
    public static function requestSafeRemote(
        string $method,
        string $url,
        string $body = '',
        array $headers = [],
        int $timeout = 20,
        int $maxResponseBytes = self::SAFE_REMOTE_MAX_BYTES
    ): array {
        $target = UrlGuard::safeRemoteTarget($url);
        if ($target === null) {
            return ['status' => 0, 'body' => '', 'error' => 'unsafe remote URL'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'secure remote requests require cURL'];
        }

        return self::viaPinnedCurl(
            $method,
            $url,
            $body,
            $headers,
            $timeout,
            $target,
            max(1, $maxResponseBytes)
        );
    }

    /** @param array<int, string> $headers */
    public static function getSafeRemote(
        string $url,
        array $headers = [],
        int $timeout = 20,
        int $maxResponseBytes = self::SAFE_REMOTE_MAX_BYTES
    ): array {
        return self::requestSafeRemote('GET', $url, '', $headers, $timeout, $maxResponseBytes);
    }

    private static function viaCurl(string $method, string $url, string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== '' || $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $response === false ? (string) curl_error($ch) : '';

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
            'error' => $error,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @param array{host:string,port:int,ips:list<string>} $target
     * @return array{status:int, body:string, error:string}
     */
    private static function viaPinnedCurl(
        string $method,
        string $url,
        string $body,
        array $headers,
        int $timeout,
        array $target,
        int $maxResponseBytes
    ): array {
        $responseBody = '';
        $tooLarge = false;
        $addresses = [];
        foreach ($target['ips'] as $ip) {
            $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $addresses[] = $address;
        }
        $resolve = [
            $target['host'] . ':' . $target['port'] . ':' . implode(',', $addresses),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge, $maxResponseBytes): int {
                if (strlen($responseBody) + strlen($chunk) > $maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== '' || strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = trim((string) curl_getinfo($ch, CURLINFO_PRIMARY_IP), '[]');
        $error = $ok === false ? (string) curl_error($ch) : '';

        if ($tooLarge) {
            return ['status' => 0, 'body' => '', 'error' => 'remote response exceeds size limit'];
        }
        $primaryAllowed = false;
        $primaryPacked = @inet_pton($primaryIp);
        foreach ($target['ips'] as $allowedIp) {
            $allowedPacked = @inet_pton($allowedIp);
            if ($primaryPacked !== false
                && $allowedPacked !== false
                && hash_equals($allowedPacked, $primaryPacked)) {
                $primaryAllowed = true;
                break;
            }
        }
        if (!$primaryAllowed) {
            return ['status' => 0, 'body' => '', 'error' => 'remote IP changed during request'];
        }

        return [
            'status' => $status,
            'body' => $ok === false ? '' : $responseBody,
            'error' => $error,
        ];
    }

    private static function viaStream(string $method, string $url, string $body, array $headers, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        // Код ответа раньше читался из $http_response_header — в PHP 8.5 эта
        // переменная объявлена устаревшей. Те же заголовки отдаёт
        // stream_get_meta_data() открытого потока, поэтому запрос идёт через
        // fopen(), а не file_get_contents().
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return ['status' => 0, 'body' => '', 'error' => 'stream request failed'];
        }
        $meta = stream_get_meta_data($stream);
        $result = stream_get_contents($stream);
        fclose($stream);

        $status = 0;
        $wrapperData = $meta['wrapper_data'] ?? [];
        if (is_array($wrapperData)) {
            foreach ($wrapperData as $header) {
                // При редиректе строк состояния несколько; берём последнюю —
                // она относится к телу, которое в итоге вернулось.
                if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                    $status = (int) $m[1];
                }
            }
        }

        return [
            'status' => $status,
            'body' => is_string($result) ? $result : '',
            'error' => $result === false ? 'stream request failed' : '',
        ];
    }
}
