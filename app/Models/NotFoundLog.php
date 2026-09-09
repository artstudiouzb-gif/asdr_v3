<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;

/**
 * 404-трекер: агрегирует пути, вызвавшие 404 (счётчик + последний внешний
 * referer). Список показывается на странице «Редиректы» — старую ссылку можно
 * в один клик превратить в 301-редирект. Мусор сканеров отфильтровывается.
 */
final class NotFoundLog
{
    /** Максимум различных путей в журнале (защита от раздувания сканерами). */
    private const MAX_ROWS = 5000;

    /** Статические расширения и следы сканеров ботов — не записываем. */
    private const NOISE_PATTERN =
        '~\.(php|env|git|sql|asp|aspx|cgi|jsp|xml|txt|js|css|map|ico|png|jpe?g|gif|webp|svg|woff2?|ttf|zip|gz|bak)$|wp-(admin|login|content|includes)|/\.(git|env|well-known/(?!security))~i';

    /**
     * Путь похож на скан или на статику, а не на попытку человека открыть
     * страницу. Публичный, потому что этим же знанием пользуется страница
     * 404: подбирать похожие материалы для `/wp-login.php` незачем.
     */
    public static function isNoise(string $path): bool
    {
        return $path === '' || preg_match(self::NOISE_PATTERN, $path) === 1;
    }

    /**
     * Фиксирует 404 (только GET, не /admin, без статики/сканеров).
     * Любая ошибка журнала не должна мешать отдаче страницы 404.
     */
    public static function record(): void
    {
        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
                return;
            }
            $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
            if ($path === '' || $path === '/' || str_starts_with($path, '/admin')
                || mb_strlen($path) > 255 || self::isNoise($path)) {
                return;
            }

            // Referer интересен только внешний (не свой домен, не пустой).
            $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($referer !== '' && $host !== '' && str_contains($referer, '://' . $host)) {
                $referer = '';
            }
            $referer = $referer !== '' ? mb_substr(Logger::redact($referer), 0, 500) : null;

            $pdo = Database::pdo();

            // Новый путь не добавляем, если журнал переполнен (существующие
            // строки продолжают счёт).
            $exists = $pdo->prepare('SELECT 1 FROM not_found_log WHERE path = :p LIMIT 1');
            $exists->execute([':p' => $path]);
            if (!$exists->fetchColumn()) {
                $count = (int) $pdo->query('SELECT COUNT(*) FROM not_found_log')->fetchColumn();
                if ($count >= self::MAX_ROWS) {
                    return;
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO not_found_log (path, hits, last_referer, first_hit_at, last_hit_at)
                 VALUES (:p, 1, :r, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                     hits = hits + 1,
                     last_referer = COALESCE(VALUES(last_referer), last_referer),
                     last_hit_at = NOW()'
            );
            $stmt->execute([':p' => $path, ':r' => $referer]);
        } catch (\Throwable $e) {
            error_log(Logger::redact('NotFoundLog failed: ' . $e->getMessage()));
        }
    }

    /**
     * Топ 404-путей для админки (чаще всего — выше; внешний referer — признак
     * живой старой ссылки).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function top(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return Database::pdo()->query(
            "SELECT * FROM not_found_log ORDER BY (last_referer IS NOT NULL) DESC, hits DESC, last_hit_at DESC LIMIT {$limit}"
        )->fetchAll();
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM not_found_log WHERE id = :id')->execute([':id' => $id]);
    }

    /** Убирает путь из журнала (после создания редиректа на него). */
    public static function deleteByPath(string $path): void
    {
        Database::pdo()->prepare('DELETE FROM not_found_log WHERE path = :p')->execute([':p' => $path]);
    }

    /** Чистка неактуальных записей (вызывается из gdpr_cleanup). */
    public static function purgeOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $stmt = Database::pdo()->prepare('DELETE FROM not_found_log WHERE last_hit_at < DATE_SUB(NOW(), INTERVAL :d DAY)');
        $stmt->bindValue(':d', $days, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
