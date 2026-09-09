<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;

/**
 * Журнал ошибок сайта: перехваченные исключения и фаталы с объяснением
 * «откуда и почему» понятным языком. Пишется из ErrorHandler, поэтому
 * обязан быть fail-safe: журнал не должен ронять обработку самой ошибки
 * (например, когда недоступна БД — молча пропускаем).
 *
 * Хранение: максимум RETENTION_DAYS дней (авточистка при записи и просмотре)
 * либо ручная очистка кнопкой в панели.
 */
final class ErrorLog
{
    public const RETENTION_DAYS = 7;

    /**
     * Правила «перевода» технических сообщений: regex (по message или file)
     * => понятное объяснение. Порядок важен — берётся первое совпадение.
     */
    private const EXPLANATIONS = [
        '/SQLSTATE\[HY000\]\s*\[(1045|2002|2006)\]|Connection refused|server has gone away/iu'
            => 'Не удалось подключиться к базе данных. Обычно это сбой или перегрузка сервера БД на хостинге либо неверные доступы в config.php.',
        '/Unknown column|Base table or view not found|SQLSTATE\[42S/iu'
            => 'Структура базы данных не совпадает с кодом: не хватает таблицы или колонки. Скорее всего, после обновления не применены миграции (php database/migrate.php).',
        '/SQLSTATE|PDOException/iu'
            => 'Ошибка запроса к базе данных. Запрос не выполнился — подробности в технических деталях.',
        '/Allowed memory size/iu'
            => 'PHP не хватило памяти при обработке страницы. Часто причина — слишком большой файл или изображение; можно увеличить memory_limit у хостинга.',
        '/Maximum execution time/iu'
            => 'Страница выполнялась дольше разрешённого времени и была остановлена. Обычно — медленный внешний сервис или тяжёлая операция.',
        '/failed to open stream.*(permission denied)|mkdir\(\).*(permission|denied)|file_put_contents/iu'
            => 'Нет прав на запись файла на диске. Проверьте права на каталоги storage/ и public/uploads/.',
        '/failed to open stream|no such file or directory|include|require/iu'
            => 'Не найден нужный файл на диске. Возможно, файлы сайта загружены не полностью или повреждены при обновлении.',
        '/cURL error|timed out|Could not resolve host|SSL certificate/iu'
            => 'Не удалось обратиться к внешнему сервису (сеть, таймаут или SSL). Это внешняя проблема — Telegram, CDN, почта и т.п.; обычно проходит само.',
        '/syntax error|Parse error|unexpected token/iu'
            => 'Синтаксическая ошибка в PHP-файле — код повреждён или отредактирован с опечаткой. Файл указан в колонке «Где».',
        '/Call to undefined (function|method)|Class .* not found/iu'
            => 'Код обращается к несуществующей функции или классу. Обычно — файлы сайта обновлены частично либо несовместимая версия PHP.',
        '/Undefined (variable|array key|property|index|offset)/iu'
            => 'Программная ошибка: обращение к несуществующей переменной или ключу. Это дефект кода — сообщите разработчику с текстом из технических деталей.',
        '/TypeError|ArgumentCountError|must be of( the)? type/iu'
            => 'Программная ошибка: функция получила данные неожиданного типа. Это дефект кода — сообщите разработчику с текстом из технических деталей.',
        '/Division by zero/iu'
            => 'Программная ошибка: деление на ноль. Это дефект кода — сообщите разработчику.',
        '/CSRF/iu'
            => 'Отклонена форма с недействительным токеном безопасности (CSRF). Обычно — устаревшая вкладка или истёкшая сессия; не атака, если случай единичный.',
    ];

    /** Понятное объяснение технического сообщения об ошибке. */
    public static function explain(string $message): string
    {
        foreach (self::EXPLANATIONS as $pattern => $explanation) {
            if (preg_match($pattern, $message) === 1) {
                return $explanation;
            }
        }

        return 'Внутренняя ошибка приложения. Причина видна в технических деталях — при повторениях сообщите разработчику.';
    }

    /**
     * Записывает ошибку. Никогда не бросает исключений (fail-safe).
     * Заодно удаляет записи старше срока хранения.
     */
    public static function record(string $level, string $message, string $file, int $line): void
    {
        $message = Logger::redact($message);
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO error_log (level, human, message, file, line, url, ip, created_at)
                 VALUES (:level, :human, :message, :file, :line, :url, :ip, NOW())'
            );
            $stmt->execute([
                ':level' => mb_substr(strtoupper($level), 0, 10),
                ':human' => mb_substr(self::explain($message), 0, 500),
                ':message' => mb_substr($message, 0, 10000),
                ':file' => mb_substr($file, 0, 500),
                ':line' => max(0, $line),
                ':url' => mb_substr(Logger::redact((string) ($_SERVER['REQUEST_URI'] ?? 'cli')), 0, 500),
                ':ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]);
            self::purgeExpired();
        } catch (\Throwable $e) {
            // БД недоступна или таблицы ещё нет — файл-лог всё равно ведётся.
            error_log(Logger::redact('Error log failed: ' . $e->getMessage()));
        }
    }

    /** Удаляет записи старше срока хранения; возвращает число удалённых. */
    public static function purgeExpired(): int
    {
        try {
            $stmt = Database::pdo()->prepare(
                'DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)'
            );
            $stmt->bindValue(':d', self::RETENTION_DAYS, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Полная ручная очистка журнала; возвращает число удалённых. */
    public static function clear(): int
    {
        $stmt = Database::pdo()->query('DELETE FROM error_log');

        return $stmt->rowCount();
    }

    /**
     * Поиск с фильтрами и пагинацией (по образцу AuditLog::search).
     *
     * @param array{level?: string, q?: string} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['level'])) {
            $where[] = 'level = :level';
            $params[':level'] = strtoupper((string) $filters['level']);
        }
        if (!empty($filters['q'])) {
            // Три отдельных плейсхолдера: PDO без эмуляции не допускает повтор имени.
            $where[] = '(message LIKE :q1 OR file LIKE :q2 OR url LIKE :q3)';
            $like = '%' . $filters['q'] . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        $suffix = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM error_log' . $suffix);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            'SELECT * FROM error_log' . $suffix . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
