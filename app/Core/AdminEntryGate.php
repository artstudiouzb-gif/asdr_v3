<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Скрытый входной шлюз административной панели.
 *
 * Пока ADMIN_ENTRY_PATH не настроен, поведение остаётся прежним. После
 * включения прямые неавторизованные обращения к /admin/* получают обычный
 * 404. Посещение секретного пути выдаёт короткоживущий подписанный cookie и
 * перенаправляет на форму входа или на безопасный URL восстановления пароля.
 */
final class AdminEntryGate
{
    private const COOKIE_NAME = 'asdr_admin_gate';
    private const DEFAULT_TTL = 1800;
    private const LOGOUT_GRACE_TTL = 120;

    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli'
            || !defined('APP_INSTALLED')
            || !APP_INSTALLED
            || !self::enabled()) {
            return;
        }

        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $entryPath = self::entryPath();
        if ($entryPath === null) {
            return;
        }

        $isAdminPath = $path === '/admin' || str_starts_with($path, '/admin/');
        $isEntryPath = hash_equals($entryPath, $path);
        if (!$isAdminPath && !$isEntryPath) {
            return;
        }

        if (!self::ipAllowed()) {
            self::notFound();
        }

        if ($isEntryPath) {
            self::openEntry();
        }

        // Действующая административная сессия важнее gateway-cookie. При
        // первом запросе после успешного входа временный пропуск удаляется.
        if (Session::hasCookie() && Auth::check()) {
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($path === '/admin/logout' && $method === 'POST') {
                // Контроллер выхода перенаправляет на /admin/login. Короткий
                // одноразовый запас времени не превращает этот редирект в 404.
                self::grant(self::LOGOUT_GRACE_TTL);
            } else {
                self::revoke();
            }
            return;
        }

        if (self::hasValidGrant()) {
            return;
        }

        self::notFound();
    }

    public static function enabled(): bool
    {
        return self::entryPath() !== null && self::signingKey() !== '';
    }

    public static function entryPath(): ?string
    {
        $environment = getenv('ADMIN_ENTRY_PATH');
        $configured = is_string($environment) && trim($environment) !== ''
            ? $environment
            : (string) Config::get('security.admin_entry_path', '');

        return self::normalizePath($configured);
    }

    public static function normalizePath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/[\x00-\x20\x7F]/', $raw) === 1) {
            return null;
        }

        $path = '/' . ltrim($raw, '/');
        $segment = substr($path, 1);
        if (strlen($segment) < 16 || strlen($segment) > 96) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]+$/', $segment) !== 1) {
            return null;
        }

        $reserved = ['admin', 'api', 'assets', 'health', 'install', 'repo', 'uploads'];
        $lower = strtolower($segment);
        foreach ($reserved as $prefix) {
            if ($lower === $prefix || str_starts_with($lower, $prefix . '-') || str_starts_with($lower, $prefix . '_')) {
                return null;
            }
        }

        return $path;
    }

    /**
     * Формирует ссылку, которую можно помещать в письмо восстановления.
     * Внешние адреса и произвольные административные маршруты не принимаются.
     */
    public static function entryUrl(string $next = '/admin/login'): string
    {
        $next = self::sanitizeNext($next);
        $path = self::entryPath();
        if ($path === null || !self::enabled()) {
            return $next;
        }

        return $path . '?next=' . rawurlencode($next);
    }

    public static function sanitizeNext(mixed $raw): string
    {
        if (!is_string($raw)
            || $raw === ''
            || preg_match('/[\x00-\x20\x7F]/', $raw) === 1) {
            return '/admin/login';
        }

        if (in_array($raw, ['/admin/login', '/admin/login/2fa', '/admin/forgot'], true)) {
            return $raw;
        }
        if (preg_match('#^/admin/reset/[a-f0-9]{64}$#', $raw) === 1) {
            return $raw;
        }

        return '/admin/login';
    }

    public static function hasPresentedCookie(): bool
    {
        return isset($_COOKIE[self::COOKIE_NAME])
            && is_string($_COOKIE[self::COOKIE_NAME])
            && $_COOKIE[self::COOKIE_NAME] !== '';
    }

    public static function hasValidGrant(): bool
    {
        $value = $_COOKIE[self::COOKIE_NAME] ?? '';

        return is_string($value) && self::validateGrantValue(
            $value,
            time(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }

    public static function issueGrantValue(int $expiresAt, string $userAgent = ''): string
    {
        $nonce = bin2hex(random_bytes(16));
        $payload = 'v1|' . $expiresAt . '|' . $nonce . '|' . hash('sha256', $userAgent);
        $signature = hash_hmac('sha256', $payload, self::signingKey());

        return 'v1.' . $expiresAt . '.' . $nonce . '.' . $signature;
    }

    public static function validateGrantValue(
        string $value,
        ?int $now = null,
        ?string $userAgent = null
    ): bool {
        $key = self::signingKey();
        if ($key === '') {
            return false;
        }

        $parts = explode('.', $value);
        if (count($parts) !== 4 || $parts[0] !== 'v1' || !ctype_digit($parts[1])) {
            return false;
        }

        [$version, $expiresRaw, $nonce, $providedSignature] = $parts;
        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $providedSignature) !== 1) {
            return false;
        }

        $now ??= time();
        $expiresAt = (int) $expiresRaw;
        if ($expiresAt <= $now || $expiresAt > $now + 86400) {
            return false;
        }

        $userAgent ??= (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $payload = $version . '|' . $expiresAt . '|' . $nonce . '|' . hash('sha256', $userAgent);
        $expectedSignature = hash_hmac('sha256', $payload, $key);

        return hash_equals($expectedSignature, $providedSignature);
    }

    public static function revoke(): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE_NAME, '', [
                'expires' => time() - 3600,
                'path' => '/admin',
                'secure' => RequestUrl::isHttps(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private static function openEntry(): never
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            self::notFound();
        }

        try {
            if (!RateLimiter::throttle(
                'admin_entry',
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                30,
                5,
                false
            )) {
                self::notFound();
            }
        } catch (\Throwable) {
            // Шлюз остаётся доступным при временной ошибке таблицы лимитов;
            // сама форма входа всё равно имеет отдельный строгий throttling.
        }

        self::grant();
        $next = self::sanitizeNext($_GET['next'] ?? '/admin/login');
        header('Cache-Control: private, no-store');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow');
        header('Location: ' . $next, true, 302);
        exit;
    }

    private static function grant(?int $ttl = null): void
    {
        $ttl ??= (int) Config::get('security.admin_entry_ttl', self::DEFAULT_TTL);
        $ttl = max(60, min(3600, $ttl));
        $expiresAt = time() + $ttl;
        $value = self::issueGrantValue(
            $expiresAt,
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $value, [
                'expires' => $expiresAt,
                'path' => '/admin',
                'secure' => RequestUrl::isHttps(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    private static function signingKey(): string
    {
        $environment = getenv('ADMIN_ENTRY_KEY');
        $key = is_string($environment) && trim($environment) !== ''
            ? trim($environment)
            : trim((string) Config::get('security.admin_entry_key', ''));
        if ($key === '') {
            $key = trim((string) Config::get('crypto.encryption_key', ''));
        }

        return strlen($key) >= 32 ? $key : '';
    }

    private static function ipAllowed(): bool
    {
        $ranges = Config::get('security.admin_entry_allowed_cidrs', []);
        if (is_string($ranges)) {
            $ranges = preg_split('/[\s,]+/', $ranges, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($ranges) || $ranges === []) {
            return true;
        }

        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip === '') {
            return false;
        }
        foreach ($ranges as $range) {
            if (is_string($range) && ClientIp::inCidr($ip, trim($range))) {
                return true;
            }
        }

        return false;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        View::render('errors/404');
        exit;
    }
}
