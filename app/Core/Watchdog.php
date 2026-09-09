<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сторож тишины: замечает то, что **не** произошло.
 *
 * Об ошибках CMS сообщает сразу — их видно по исключению или ответу API. А
 * самый частый отказ на shared-хостинге беззвучный: cron перестал вызывать
 * воркер, очередь публикаций стоит, на диске кончилось место. Ничего не
 * падает, никто не узнаёт, пока не откроет админку или не спросят «почему
 * новость не вышла».
 *
 * Heartbeat и /health такие признаки уже считают, но только когда их кто-то
 * спрашивает. Этот класс запускается по cron и сам сообщает в Telegram —
 * один раз на проблему, плюс отдельное сообщение, когда она ушла.
 */
final class Watchdog
{
    /** Публикация ждёт отправки дольше этого — очередь стоит (секунды). */
    private const QUEUE_STUCK_AFTER = 3600;

    /** Меньше этого свободного места — предупреждаем (байты). */
    private const DISK_LOW = 314572800; // 300 МБ

    /** Ключ настройки, где хранится список уже объявленных проблем. */
    private const STATE_KEY = 'watchdog_active_alerts';

    /**
     * Текущие проблемы: ключ → человеческое описание. Пустой массив означает,
     * что всё в порядке.
     *
     * @return array<string,string>
     */
    public static function check(): array
    {
        $problems = [];

        foreach (Heartbeat::status() as $name => $state) {
            if (!empty($state['stale'])) {
                $hours = (int) round(((int) $state['age']) / 3600);
                $problems['worker:' . $name] = sprintf(
                    'Воркер «%s» молчит %s. Обычно он запускается чаще — похоже, cron перестал его вызывать.',
                    $name,
                    $hours >= 1 ? $hours . ' ч' : (int) round(((int) $state['age']) / 60) . ' мин'
                );
            }
        }

        foreach (self::stuckQueues() as $key => $text) {
            $problems[$key] = $text;
        }

        $free = self::freeDiskBytes();
        if ($free !== null && $free < self::DISK_LOW) {
            $problems['disk'] = sprintf(
                'На диске осталось %s. Кончится место — перестанут сохраняться загрузки, кэш и резервные копии.',
                self::formatBytes($free)
            );
        }

        return $problems;
    }

    /**
     * Проверяет и сообщает об изменениях. Возвращает, что было отправлено:
     * повторных сообщений об одной и той же проблеме не будет, зато об уходе
     * проблемы сообщается отдельно — иначе непонятно, чинить ещё или уже нет.
     *
     * @return array{problems:array<string,string>, new:list<string>, resolved:list<string>, notified:bool}
     */
    public static function run(): array
    {
        $problems = self::check();
        $known = self::knownAlerts();

        $new = array_values(array_diff(array_keys($problems), $known));
        $resolved = array_values(array_diff($known, array_keys($problems)));

        $notified = false;
        if ($new !== []) {
            $lines = ['<b>Похоже, что-то остановилось</b>', ''];
            foreach ($new as $key) {
                $lines[] = '• ' . htmlspecialchars($problems[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $notified = FormNotifier::broadcast(implode("\n", $lines)) > 0;
        }
        if ($resolved !== []) {
            $notified = FormNotifier::broadcast(
                '<b>Снова в норме</b>: ' . htmlspecialchars(implode(', ', $resolved), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ) > 0 || $notified;
        }

        self::rememberAlerts(array_keys($problems));

        return ['problems' => $problems, 'new' => $new, 'resolved' => $resolved, 'notified' => $notified];
    }

    /**
     * Очереди, из которых давно ничего не уходит. Пустая очередь — норма;
     * тревожит только задача, которая ждёт отправки дольше часа.
     *
     * @return array<string,string>
     */
    private static function stuckQueues(): array
    {
        if (!Database::isConnected()) {
            return [];
        }
        // У публикаций бывает отложенная отправка: пока её время не пришло,
        // задача ждёт законно. Считаем возраст от времени, когда она стала
        // готовой, — иначе пост, запланированный на завтра, поднимал бы
        // тревогу уже через час.
        $queues = [
            'social_posts' => ['Публикации в соцсети', 'COALESCE(scheduled_at, created_at)'],
            'mail_queue' => ['Письма', 'created_at'],
        ];

        $problems = [];
        foreach ($queues as $table => [$title, $readySince]) {
            try {
                $stmt = Database::pdo()->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE status = 'pending' AND {$readySince} < (NOW() - INTERVAL :seconds SECOND)"
                );
                $stmt->bindValue(':seconds', self::QUEUE_STUCK_AFTER, \PDO::PARAM_INT);
                $stmt->execute();
                $count = (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                // Таблицы может не быть на урезанной установке — это не отказ.
                Logger::swallowed('Watchdog: не удалось проверить очередь ' . $table, $e);
                continue;
            }
            if ($count > 0) {
                $problems['queue:' . $table] = sprintf(
                    '%s: %d задач(и) ждут отправки больше часа — очередь не разбирается.',
                    $title,
                    $count
                );
            }
        }

        return $problems;
    }

    /** Свободное место в каталоге проекта; null — узнать не удалось. */
    private static function freeDiskBytes(): ?int
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $free = @disk_free_space($root);

        return is_float($free) && $free > 0 ? (int) $free : null;
    }

    /** @return list<string> */
    private static function knownAlerts(): array
    {
        $raw = (string) \App\Models\Setting::get(self::STATE_KEY, '');
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /** @param list<string> $keys */
    private static function rememberAlerts(array $keys): void
    {
        // Приведение `(string)` прятало бы отказ: `(string) false` — пустая
        // строка, то есть сторож молча забыл бы, о чём уже сообщал, и начал
        // слать те же тревоги заново. Имена воркеров свои, поэтому отказ
        // кодирования здесь — ошибка.
        \App\Models\Setting::set(self::STATE_KEY, json_encode(
            array_values($keys),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' ГБ';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576) . ' МБ';
        }

        return round($bytes / 1024) . ' КБ';
    }
}
