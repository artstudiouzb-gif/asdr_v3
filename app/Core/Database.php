<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    /**
     * Через сколько секунд простоя соединение проверяется перед запросом.
     * Импорт новостей качает каждую картинку до минуты, и MySQL успевает
     * закрыть простаивающее соединение по wait_timeout (на shared-хостинге он
     * часто 30–60 с) — следующий запрос падал «2006 MySQL server has gone
     * away». Запросы, идущие подряд, порога не достигают и лишнего обращения
     * к серверу не платят.
     */
    private const PING_IDLE_SECONDS = 2.0;

    private static ?PDO $connection = null;
    private static ?array $lastConfig = null;
    private static float $lastUsedAt = 0.0;

    public static function init(array $config): void
    {
        self::$lastConfig = $config;
        if (self::$connection !== null) {
            return;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY => true,
            ]);
            // Синхронизируем часовой пояс сессии MySQL со временем PHP. Иначе
            // NOW() в MySQL и published_at/created_at, записываемые из PHP,
            // расходятся на разницу поясов, и свежие новости/записи с фильтром
            // "published_at <= NOW()" прячутся до конца смещения (напр. на 3 часа).
            $offset = (new \DateTimeImmutable())->format('P'); // напр. +03:00
            self::$connection->exec("SET time_zone = '" . $offset . "'");
            self::$lastUsedAt = microtime(true);
        } catch (PDOException $e) {
            self::$connection = null;
            // Бросаем исключение вместо exit — вызывающий код решает, что делать
            // (fail-safe 503 в рабочем режиме или продолжение в режиме установки).
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function isConnected(): bool
    {
        return self::$connection !== null;
    }

    public static function pdo(): PDO
    {
        if (self::$connection === null) {
            if (self::$lastConfig !== null) {
                self::init(self::$lastConfig);
            } else {
                throw new \RuntimeException('Database is not initialized.');
            }
        }

        $now = microtime(true);
        if (self::$lastUsedAt > 0.0
            && ($now - self::$lastUsedAt) >= self::PING_IDLE_SECONDS
            && !self::$connection->inTransaction()
        ) {
            self::reviveIfDead();
        }
        self::$lastUsedAt = $now;

        return self::$connection;
    }

    /**
     * Проверяет соединение дешёвым SELECT 1 и переподключается, если сервер уже
     * закрыл его. Внутри транзакции метод не вызывается: переподключение молча
     * потеряло бы незакоммиченную работу, и там честнее упасть.
     */
    private static function reviveIfDead(): void
    {
        $config = self::$lastConfig;
        try {
            self::$connection?->query('SELECT 1');

            return;
        } catch (PDOException $e) {
            if ($config === null) {
                throw $e;
            }
        }

        self::$connection = null;
        self::init($config);
    }

    /**
     * Выполняет единицу работы атомарно. Вложенный вызов присоединяется к уже
     * открытой транзакции, поэтому модели можно безопасно компоновать.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $owner = !$pdo->inTransaction();
        if ($owner) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback($pdo);
            if ($owner) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owner && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

}
