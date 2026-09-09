<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runtime configuration for the hidden administrative entry point.
 *
 * Environment variables remain the highest-priority deployment option. For
 * shared hosting and the web installer, the same values can be stored in a
 * small JSON file under storage/ (outside the public web root).
 */
final class AdminEntryConfig
{
    private const DEFAULT_TTL = 1800;
    private const MIN_TTL = 300;
    private const MAX_TTL = 3600;

    /** Load the protected runtime file into Config before AdminEntryGate runs. */
    public static function loadIntoConfig(): void
    {
        // A deployment-managed path wins completely over the runtime file.
        if (self::environmentManaged()) {
            return;
        }

        $runtime = self::readRuntime();
        if ($runtime === null) {
            return;
        }

        $security = Config::get('security', []);
        if (!is_array($security)) {
            $security = [];
        }

        $enabled = (bool) ($runtime['enabled'] ?? false);
        $path = $enabled ? AdminEntryGate::normalizePath((string) ($runtime['path'] ?? '')) : null;
        $security['admin_entry_path'] = $path ?? '';
        $security['admin_entry_ttl'] = self::normalizeTtl((int) ($runtime['ttl'] ?? self::DEFAULT_TTL));
        $security['admin_entry_allowed_cidrs'] = self::normalizeCidrs($runtime['allowed_cidrs'] ?? []);

        Config::merge(['security' => $security]);
    }

    /**
     * Generates and persists an entry path when installation finishes.
     * Existing environment/config/runtime values are preserved.
     */
    public static function ensureGenerated(): string
    {
        self::loadIntoConfig();
        $existing = AdminEntryGate::entryPath();
        if ($existing !== null) {
            return $existing;
        }

        self::assertSigningKeyAvailable();
        $path = self::generatePath();
        self::save($path, self::DEFAULT_TTL, []);

        return $path;
    }

    public static function generatePath(): string
    {
        return '/control-' . bin2hex(random_bytes(12));
    }

    /** @param list<string> $allowedCidrs */
    public static function save(string $path, int $ttl, array $allowedCidrs): void
    {
        if (self::environmentManaged()) {
            throw new \RuntimeException('Адрес входа управляется переменной окружения ADMIN_ENTRY_PATH.');
        }

        $normalizedPath = AdminEntryGate::normalizePath($path);
        if ($normalizedPath === null) {
            throw new \InvalidArgumentException(
                'Адрес должен быть одним длинным сегментом: минимум 16 символов, только буквы, цифры, дефис и подчёркивание.'
            );
        }

        self::assertSigningKeyAvailable();
        $payload = [
            'version' => 1,
            'enabled' => true,
            'path' => $normalizedPath,
            'ttl' => self::normalizeTtl($ttl),
            'allowed_cidrs' => self::normalizeCidrs($allowedCidrs),
            'updated_at' => gmdate('c'),
        ];
        self::writeRuntime($payload);
        self::loadIntoConfig();
    }

    public static function disable(): void
    {
        if (self::environmentManaged()) {
            throw new \RuntimeException('Адрес входа управляется переменной окружения ADMIN_ENTRY_PATH.');
        }

        $payload = [
            'version' => 1,
            'enabled' => false,
            'path' => '',
            'ttl' => self::DEFAULT_TTL,
            'allowed_cidrs' => [],
            'updated_at' => gmdate('c'),
        ];
        self::writeRuntime($payload);
        self::loadIntoConfig();
    }

    /** @return array{enabled:bool,path:string,url:string,ttl:int,allowed_cidrs:list<string>,environment_managed:bool,writable:bool} */
    public static function state(): array
    {
        self::loadIntoConfig();
        $path = AdminEntryGate::entryPath() ?? '';
        $base = AppUrl::base();
        if ($base === '') {
            $base = RequestUrl::origin();
        }

        $cidrs = Config::get('security.admin_entry_allowed_cidrs', []);
        if (is_string($cidrs)) {
            $cidrs = preg_split('/[\s,]+/', $cidrs, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($cidrs)) {
            $cidrs = [];
        }

        return [
            'enabled' => AdminEntryGate::enabled(),
            'path' => $path,
            'url' => $path !== '' ? rtrim($base, '/') . $path : '',
            'ttl' => self::normalizeTtl((int) Config::get('security.admin_entry_ttl', self::DEFAULT_TTL)),
            'allowed_cidrs' => self::normalizeCidrs($cidrs),
            'environment_managed' => self::environmentManaged(),
            'writable' => self::isWritable(),
        ];
    }

    public static function environmentManaged(): bool
    {
        $path = getenv('ADMIN_ENTRY_PATH');

        return is_string($path) && trim($path) !== '';
    }

    private static function assertSigningKeyAvailable(): void
    {
        $environmentKey = getenv('ADMIN_ENTRY_KEY');
        $key = is_string($environmentKey) && trim($environmentKey) !== ''
            ? trim($environmentKey)
            : trim((string) Config::get('security.admin_entry_key', ''));
        if ($key === '') {
            $key = trim((string) Config::get('crypto.encryption_key', ''));
        }
        if (strlen($key) < 32) {
            throw new \RuntimeException('Не настроен APP_ENCRYPTION_KEY или ADMIN_ENTRY_KEY длиной не менее 32 символов.');
        }
    }

    private static function normalizeTtl(int $ttl): int
    {
        return max(self::MIN_TTL, min(self::MAX_TTL, $ttl));
    }

    /** @return list<string> */
    private static function normalizeCidrs(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $cidr = trim($value);
            if ($cidr === '') {
                continue;
            }
            if (!self::validCidr($cidr)) {
                throw new \InvalidArgumentException('Некорректный IP/CIDR в списке доступа: ' . $cidr);
            }
            $out[] = $cidr;
        }

        return array_values(array_unique($out));
    }

    private static function validCidr(string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP) !== false;
        }

        [$network, $prefixRaw] = array_pad(explode('/', $cidr, 2), 2, '');
        if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefixRaw)) {
            return false;
        }
        $bytes = @inet_pton($network);
        if ($bytes === false) {
            return false;
        }
        $prefix = (int) $prefixRaw;

        return $prefix >= 0 && $prefix <= strlen($bytes) * 8;
    }

    /** @return array<string,mixed>|null */
    private static function readRuntime(): ?array
    {
        $file = self::storageFile();
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $payload */
    private static function writeRuntime(array $payload): void
    {
        $file = self::storageFile();
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог для защищённой настройки входа.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Каталог storage недоступен для записи.');
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Не удалось сформировать настройку входа.');
        }
        $json .= PHP_EOL;

        $temporary = tempnam($directory, '.admin-entry-');
        if ($temporary === false) {
            throw new \RuntimeException('Не удалось создать временный файл настройки.');
        }
        try {
            $written = file_put_contents($temporary, $json, LOCK_EX);
            if ($written !== strlen($json)) {
                throw new \RuntimeException('Настройка входа записана не полностью.');
            }
            if (!@chmod($temporary, 0600)) {
                throw new \RuntimeException('Не удалось установить безопасные права файла настройки.');
            }
            if (!@rename($temporary, $file)) {
                throw new \RuntimeException('Не удалось атомарно сохранить настройку входа.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function storageFile(): string
    {
        $configured = trim((string) Config::get('security.admin_entry_storage_file', ''));

        return $configured !== ''
            ? $configured
            : (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/admin-entry.json';
    }

    private static function isWritable(): bool
    {
        $file = self::storageFile();
        if (is_file($file)) {
            return is_writable($file);
        }
        $directory = dirname($file);

        return is_dir($directory) ? is_writable($directory) : is_writable(dirname($directory));
    }
}
