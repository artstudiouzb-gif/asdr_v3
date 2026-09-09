<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Простое файловое кеширование в storage/cache/. Ключи вида "page:5:ru"
 * отображаются в путь storage/cache/page/5/ru.cache, что позволяет
 * инвалидировать целые группы (например, все языки одной страницы) удалением
 * поддиректории.
 */
final class Cache
{
    /** @var array<string, array{value: mixed, expires_at: int}> */
    private static array $memoryCache = [];
    private static ?bool $hasApcu = null;

    private static function apcuEnabled(): bool
    {
        if (self::$hasApcu === null) {
            self::$hasApcu = function_exists('apcu_fetch') && (bool) ini_get('apc.enabled') && (PHP_SAPI !== 'cli' || (bool) ini_get('apc.enable_cli'));
        }
        return self::$hasApcu;
    }

    private static function dir(): string
    {
        return APP_ROOT . '/storage/cache';
    }

    private static function pathFor(string $key): string
    {
        $segments = array_map(
            static fn ($s) => preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $s) ?? '_',
            explode(':', $key)
        );
        $file = array_pop($segments);
        $sub = $segments === [] ? '' : '/' . implode('/', $segments);

        return self::dir() . $sub . '/' . $file . '.cache';
    }

    /**
     * Чтение с учётом TTL: если $ttl > 0 и файл старше — считаем промахом.
     */
    private static function getFresh(string $key, int $ttl): mixed
    {
        $path = self::pathFor($key);
        if ($ttl > 0 && is_file($path) && (time() - (int) @filemtime($path)) > $ttl) {
            unset(self::$memoryCache[$key]);
            if (self::apcuEnabled()) {
                apcu_delete('asdr:' . $key);
            }
            return null;
        }

        // 1. L1 Request Memory Cache
        if (isset(self::$memoryCache[$key])) {
            $item = self::$memoryCache[$key];
            if ($item['expires_at'] === 0 || $item['expires_at'] >= time()) {
                return $item['value'];
            }
            unset(self::$memoryCache[$key]);
        }

        // 2. L1.5 Shared APCu Cache
        if (self::apcuEnabled()) {
            $apcuKey = 'asdr:' . $key;
            $success = false;
            $val = apcu_fetch($apcuKey, $success);
            if ($success && is_array($val) && isset($val['value'])) {
                self::$memoryCache[$key] = ['value' => $val['value'], 'expires_at' => $ttl > 0 ? time() + $ttl : 0];
                return $val['value'];
            }
        }

        return self::get($key);
    }

    public static function get(string $key): mixed
    {
        if (isset(self::$memoryCache[$key])) {
            $item = self::$memoryCache[$key];
            if ($item['expires_at'] === 0 || $item['expires_at'] >= time()) {
                return $item['value'];
            }
            unset(self::$memoryCache[$key]);
        }

        if (self::apcuEnabled()) {
            $apcuKey = 'asdr:' . $key;
            $success = false;
            $val = apcu_fetch($apcuKey, $success);
            if ($success && is_array($val) && isset($val['value'])) {
                self::$memoryCache[$key] = ['value' => $val['value'], 'expires_at' => 0];
                return $val['value'];
            }
        }

        $path = self::pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);

        $result = $data === false && $raw !== serialize(false) ? null : $data;
        if ($result !== null) {
            self::$memoryCache[$key] = ['value' => $result, 'expires_at' => 0];
        }

        return $result;
    }

    public static function put(string $key, mixed $value, int $ttl = 0): void
    {
        $expiresAt = $ttl > 0 ? time() + $ttl : 0;
        self::$memoryCache[$key] = ['value' => $value, 'expires_at' => $expiresAt];

        if (self::apcuEnabled()) {
            apcu_store('asdr:' . $key, ['value' => $value], $ttl);
        }

        $path = self::pathFor($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($path, serialize($value), LOCK_EX);
    }

    /** Анти-stampede: сколько раз и с каким шагом ждать чужую генерацию. */
    private const LOCK_WAIT_ATTEMPTS = 30;
    private const LOCK_WAIT_MICROSECONDS = 100_000; // суммарно ~3 секунды

    /**
     * Ленивая генерация с защитой от cache stampede: после сброса кэша
     * значение генерирует только первый пришедший поток (flock на lock-файле),
     * остальные одновременные запросы ждут готовый кэш (usleep) вместо того,
     * чтобы лавиной нагружать MySQL и CssScoper.
     *
     * @param callable():mixed $callback
     * @param int $ttl если > 0 — максимальный возраст записи в секундах (по
     *                 истечении кэш считается устаревшим и пересобирается).
     */
    public static function remember(string $key, callable $callback, int $ttl = 0): mixed
    {
        $cached = self::getFresh($key, $ttl);
        if ($cached !== null) {
            return $cached;
        }

        $lockPath = self::pathFor($key) . '.lock';
        $dir = dirname($lockPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $callback(); // ФС недоступна — работаем без кэша
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return $callback();
        }

        try {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                // Мы — генератор. Перепроверка: кэш мог появиться, пока брали lock.
                $cached = self::getFresh($key, $ttl);
                if ($cached !== null) {
                    return $cached;
                }
                $value = $callback();
                self::put($key, $value);

                return $value;
            }

            // Генерирует другой поток — ждём готовый кэш.
            for ($i = 0; $i < self::LOCK_WAIT_ATTEMPTS; $i++) {
                usleep(self::LOCK_WAIT_MICROSECONDS);
                $cached = self::get($key);
                if ($cached !== null) {
                    return $cached;
                }
            }

            // Генератор завис/упал — не блокируем посетителя, считаем сами.
            $value = $callback();
            self::put($key, $value);

            return $value;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function forget(string $key): void
    {
        unset(self::$memoryCache[$key]);
        if (self::apcuEnabled()) {
            apcu_delete('asdr:' . $key);
        }

        $path = self::pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Инвалидация группы: удаляет поддиректорию, соответствующую префиксу
     * (например, "page:5" -> storage/cache/page/5).
     */
    public static function forgetPrefix(string $prefix): void
    {
        $cleanPrefix = rtrim($prefix, ':');

        // Очищаем L1 память для совпавших ключей
        foreach (array_keys(self::$memoryCache) as $k) {
            if (str_starts_with((string) $k, $cleanPrefix)) {
                unset(self::$memoryCache[$k]);
            }
        }

        if (self::apcuEnabled() && class_exists('\APCUIterator')) {
            $iter = new \APCUIterator('/^asdr:' . preg_quote($cleanPrefix, '/') . '/');
            apcu_delete($iter);
        }

        $parts = array_values(array_filter(explode(':', $cleanPrefix), static fn ($s) => $s !== ''));
        $segments = array_map(
            static fn ($s) => preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $s),
            $parts
        );
        $target = self::dir() . ($segments === [] ? '' : '/' . implode('/', $segments));
        self::removeRecursive($target);

        // Контент страниц изменился — очищаем и внешний CDN-кэш (Cloudflare),
        // если интеграция включена. Безопасно: не чаще раза за запрос и no-op,
        // когда выключено.
        if (str_starts_with($cleanPrefix, 'page')) {
            Cloudflare::purgeSite();
        }
    }

    /**
     * Очищает кэш текущей страницы и всех связанных страниц из той же группы
     * переводов (RU, UZ, EN), гарантируя немедленный сброс на всех языках.
     */
    public static function clearPageCache(int $pageId): void
    {
        self::forgetPrefix('page:' . $pageId);

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT id FROM pages WHERE translation_group_id = (SELECT translation_group_id FROM pages WHERE id = :id LIMIT 1)'
            );
            $stmt->execute([':id' => $pageId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $relatedId) {
                if ((int) $relatedId > 0 && (int) $relatedId !== $pageId) {
                    self::forgetPrefix('page:' . (int) $relatedId);
                }
            }
        } catch (\Throwable) {
            self::forgetPrefix('page:');
        }
    }

    public static function flush(): void
    {
        self::$memoryCache = [];
        if (self::apcuEnabled()) {
            apcu_clear_cache();
        }

        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            // Эти файлы — не кэш контента: удаление блокировок допускает
            // параллельный запуск воркеров/бэкапа, rate-limit защищает вход,
            // chunks содержит незавершённые загрузки.
            if (in_array($item, ['rate-limits', 'chunks', '.gitkeep', 'README.md'], true)
                || str_ends_with($item, '.lock')
                || str_starts_with($item, 'worker_heartbeat_')) {
                continue;
            }
            self::removeRecursive($dir . '/' . $item);
        }
    }

    private static function removeRecursive(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            self::removeRecursive($path . '/' . $item);
        }
        @rmdir($path);
    }
}
