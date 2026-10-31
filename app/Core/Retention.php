<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сроки хранения журналов и очередей, которые растут без предела.
 *
 * Поиск пишет строку на каждый запрос (и ботов тоже), просмотры — строку на
 * новость в день, очереди писем и вебхуков хранят отправленное навсегда. Диск,
 * бэкап и репетиция восстановления росли вместе с ними, а пользы от записи
 * трёхлетней давности о доставленном письме нет. Правила объявлены одним
 * списком, а запускает их ночной `app/Console/gdpr_cleanup.php`.
 *
 * Неотправленное (`pending`) не удаляется никогда: это работа, а не история.
 */
final class Retention
{
    /**
     * Таблица => [условие на «старое», дней]. Условия опираются на уже
     * существующие индексы (created_at / status+created_at / view_date).
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const RULES = [
        'search_log' => ['created_at < NOW() - INTERVAL :days DAY', 90],
        // mostViewed смотрит не дальше года — 400 дней с запасом.
        'news_views' => ['view_date < CURRENT_DATE() - INTERVAL :days DAY', 400],
        'mail_queue' => ["status IN ('sent', 'failed', 'cancelled') AND created_at < NOW() - INTERVAL :days DAY", 90],
        'webhook_deliveries' => ["status IN ('sent', 'failed') AND created_at < NOW() - INTERVAL :days DAY", 90],
    ];

    /** Файловый кэш с ключом на каждый запрос: префикс => возраст в секундах. */
    public const CACHE_RULES = [
        'search' => 86400,
        'ai' => 2 * 86400,
    ];

    /**
     * @return array<string, int> сколько удалено по каждой таблице и префиксу кэша
     */
    public static function run(): array
    {
        $report = [];
        $pdo = Database::pdo();
        foreach (self::RULES as $table => [$condition, $days]) {
            try {
                $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $condition);
                $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
                $stmt->execute();
                $report[$table] = $stmt->rowCount();
            } catch (\PDOException $e) {
                // Таблицы может не быть на установке без модуля: это не повод
                // бросать уборку остальных.
                Logger::swallowed('retention:' . $table, $e);
            }
        }
        foreach (self::CACHE_RULES as $prefix => $age) {
            $report['cache:' . $prefix] = Cache::purgeStale($prefix, $age);
        }

        return $report;
    }
}
