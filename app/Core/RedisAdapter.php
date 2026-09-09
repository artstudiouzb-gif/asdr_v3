<?php

declare(strict_types=1);

namespace App\Core;

use Redis;
use Throwable;

/**
 * Адаптер Redis для высоконагруженных инсталляций, кластеров и очередей.
 *
 * Предоставляет:
 *  - Быстрый L2/L3 сетевой кэш (get/set/delete/deletePrefix)
 *  - Распределённые атомарные блокировки (acquireLock/releaseLock)
 *  - Быстрые очереди на списках Redis (pushQueue/popQueue/queueLength)
 *
 * Архитектурные гарантии:
 *  - Нулевые сторонние зависимости (использует нативное расширение ext-redis).
 *  - Прозрачный fallback: если Redis не установлен, не настроен или временно
 *    недоступен по сети, ни один метод не бросает исключений и не роняет сайт.
 *    Все методы безопасно возвращают null или false.
 */
final class RedisAdapter
{
    private static ?Redis $client = null;
    private static ?bool $available = null;
    private static bool $connectionAttempted = false;

    /**
     * Сброс состояния подключения (для тестов).
     */
    public static function reset(): void
    {
        if (self::$client !== null) {
            try {
                self::$client->close();
            } catch (Throwable) {
                // ignore
            }
            self::$client = null;
        }
        self::$available = null;
        self::$connectionAttempted = false;
    }

    /**
     * Проверяет, доступен ли Redis в текущем окружении.
     */
    public static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (!extension_loaded('redis')) {
            return self::$available = false;
        }

        $host = self::host();
        if ($host === '') {
            return self::$available = false;
        }

        return self::client() !== null;
    }

    /**
     * Возвращает настроенный клиент Redis или null при сбое.
     */
    public static function client(): ?Redis
    {
        if (self::$connectionAttempted) {
            return self::$client;
        }

        self::$connectionAttempted = true;

        if (!extension_loaded('redis')) {
            self::$available = false;
            return null;
        }

        $host = self::host();
        if ($host === '') {
            self::$available = false;
            return null;
        }

        $port = (int) (getenv('REDIS_PORT') ?: Config::get('redis.port', 6379));
        $password = (string) (getenv('REDIS_PASSWORD') ?: Config::get('redis.password', ''));
        $database = (int) (getenv('REDIS_DATABASE') ?: Config::get('redis.database', 0));
        $timeout = (float) (getenv('REDIS_TIMEOUT') ?: Config::get('redis.timeout', 1.0));

        try {
            $redis = new Redis();
            $connected = @$redis->connect($host, $port, $timeout);
            if (!$connected) {
                self::$available = false;
                return null;
            }

            if ($password !== '') {
                $auth = @$redis->auth($password);
                if (!$auth) {
                    self::$available = false;
                    $redis->close();
                    return null;
                }
            }

            if ($database > 0) {
                @$redis->select($database);
            }

            self::$client = $redis;
            self::$available = true;

            return self::$client;
        } catch (Throwable) {
            self::$available = false;
            return null;
        }
    }

    public static function get(string $key): mixed
    {
        $redis = self::client();
        if ($redis === null) {
            return null;
        }

        try {
            $raw = $redis->get(self::prefix() . $key);
            if ($raw === false || !is_string($raw)) {
                return null;
            }

            return @unserialize($raw, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    public static function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $redis = self::client();
        if ($redis === null) {
            return false;
        }

        try {
            $payload = serialize($value);
            $fullKey = self::prefix() . $key;

            if ($ttl > 0) {
                return (bool) $redis->setex($fullKey, $ttl, $payload);
            }

            return (bool) $redis->set($fullKey, $payload);
        } catch (Throwable) {
            return false;
        }
    }

    public static function delete(string $key): bool
    {
        $redis = self::client();
        if ($redis === null) {
            return false;
        }

        try {
            return (int) $redis->del(self::prefix() . $key) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Удаляет все ключи по маске префикса.
     */
    public static function deletePrefix(string $prefix): int
    {
        $redis = self::client();
        if ($redis === null) {
            return 0;
        }

        try {
            $pattern = self::prefix() . rtrim($prefix, ':*') . ':*';
            $keys = $redis->keys($pattern);
            if (!is_array($keys) || $keys === []) {
                return 0;
            }

            return (int) $redis->del($keys);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Распределённая атомарная блокировка (anti-stampede / singleton job).
     *
     * @return string|null Маркер блокировки (token) или null при неудаче.
     */
    public static function acquireLock(string $name, int $ttlSeconds = 10): ?string
    {
        $redis = self::client();
        if ($redis === null) {
            return null;
        }

        try {
            $token = bin2hex(random_bytes(16));
            $lockKey = self::prefix() . 'lock:' . $name;
            $acquired = $redis->set($lockKey, $token, ['NX', 'EX' => max(1, $ttlSeconds)]);

            return $acquired ? $token : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Безопасное освобождение блокировки только её владельцем.
     */
    public static function releaseLock(string $name, string $token): bool
    {
        $redis = self::client();
        if ($redis === null) {
            return false;
        }

        try {
            $lockKey = self::prefix() . 'lock:' . $name;
            // Атомарное сравнение и удаление через Lua
            $lua = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
            $res = $redis->eval($lua, [$lockKey, $token], 1);

            return (bool) $res;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Добавление задачи в очередь (RPUSH).
     */
    public static function pushQueue(string $queue, mixed $payload): bool
    {
        $redis = self::client();
        if ($redis === null) {
            return false;
        }

        try {
            return (bool) $redis->rpush(self::prefix() . 'queue:' . $queue, serialize($payload));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Извлечение задачи из очереди (LPOP).
     */
    public static function popQueue(string $queue): mixed
    {
        $redis = self::client();
        if ($redis === null) {
            return null;
        }

        try {
            $raw = $redis->lpop(self::prefix() . 'queue:' . $queue);
            if ($raw === false || !is_string($raw)) {
                return null;
            }

            return @unserialize($raw, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Длина очереди.
     */
    public static function queueLength(string $queue): int
    {
        $redis = self::client();
        if ($redis === null) {
            return 0;
        }

        try {
            return (int) $redis->llen(self::prefix() . 'queue:' . $queue);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function host(): string
    {
        return trim((string) (getenv('REDIS_HOST') ?: Config::get('redis.host', '')));
    }

    public static function prefix(): string
    {
        $prefix = (string) (getenv('REDIS_PREFIX') ?: Config::get('redis.prefix', 'asdr:'));
        return rtrim($prefix, ':') . ':';
    }
}
