<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Проверка пользовательских URL. Три задачи:
 *
 *  1. isSafeLink()   — URL для вывода в href: только http/https, mailto/tel
 *     или обычный относительный путь; javascript:/data:/file: и
 *     protocol-relative адреса отсекаются.
 *  2. isSafeMedia()  — более строгий URL для src: только http/https или
 *     локальный путь от корня сайта, без mailto/tel/data.
 *  3. isSafeRemote() — URL, по которому СЕРВЕР будет делать исходящий запрос:
 *     дополнительно резолвит хост и запрещает приватные/loopback/link-local
 *     диапазоны (защита от SSRF).
 *
 * Для фактического запроса одной проверки недостаточно: Http::getSafeRemote()
 * и Http::requestSafeRemote() закрепляют проверенный DNS-результат в cURL и
 * сверяют адрес установленного соединения.
 */
final class UrlGuard
{
    /** Ссылка безопасна для вывода в атрибут href. */
    public static function isSafeLink(string $url): bool
    {
        $url = trim($url);
        if ($url === ''
            || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
            || preg_match('/\s/u', $url) === 1
            || str_contains($url, '\\')
            || str_starts_with($url, '//')) {
            return false;
        }

        // Обычные локальные пути, якоря и query-only ссылки безопасны.
        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return true;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        // Путь вида "news/item" не имеет схемы и остаётся локальным. Если до
        // первого /, ? или # присутствует двоеточие, это попытка задать схему,
        // которую parse_url не смог корректно распознать — отклоняем fail-closed.
        if ($scheme === '') {
            $colon = strpos($url, ':');
            $separator = strcspn($url, '/?#');

            return $colon === false || $colon >= $separator;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            return !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
        }

        return in_array($scheme, ['mailto', 'tel'], true);
    }

    /**
     * URL безопасен для изображения/видео: локальный путь от корня сайта либо
     * абсолютный http(s). Произвольные относительные пути запрещены, чтобы
     * `../` не использовался как обход каталогов; protocol-relative URL тоже
     * отклоняются, чтобы схема всегда была явной.
     */
    public static function isSafeMedia(string $url): bool
    {
        $url = trim($url);
        if (!self::isSafeLink($url)
            || str_starts_with($url, '#')
            || str_starts_with($url, '?')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * URL безопасен для серверного исходящего запроса (нет SSRF): схема http/https,
     * хост существует и не указывает на приватный/loopback/link-local адрес.
     */
    public static function isSafeRemote(string $url): bool
    {
        return self::safeRemoteTarget($url) !== null;
    }

    /**
     * Возвращает уже проверенную цель для безопасного HTTP-клиента. Полученные
     * IP нужно закрепить на уровне соединения (CURLOPT_RESOLVE), иначе между
     * проверкой и запросом возможна DNS-rebinding атака.
     *
     * @return array{host:string,port:int,ips:list<string>}|null
     */
    public static function safeRemoteTarget(string $url): ?array
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        // Резолвим все A/AAAA-записи и проверяем каждую: DNS-rebinding и
        // хитрые имена не должны привести на внутренний адрес.
        $ips = self::resolveHost($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }

        return [
            'host' => $host,
            'port' => $port,
            'ips' => array_values(array_unique($ips)),
        ];
    }

    /** @return array<int, string> */
    private static function resolveHost(string $host): array
    {
        // Хост может быть литеральным IP.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        // IPv6 в скобках.
        $trimmed = trim($host, '[]');
        if (filter_var($trimmed, FILTER_VALIDATE_IP)) {
            return [$trimmed];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        return $ips;
    }

    private static function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE отсекают 10/8, 172.16/12,
        // 192.168/16, 127/8, 169.254/16, ::1, fc00::/7, fe80::/10 и т.п.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
