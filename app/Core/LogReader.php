<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Журнал ошибок для панели: читает файлы `storage/logs`, сводит одинаковые
 * записи и отдаёт их с числом повторов.
 *
 * Журнал существовал всегда, но жил файлом на shared-хостинге, куда владелец
 * не заходит: за эту неделю он дважды скачивал `error.log` и присылал его
 * инженеру, чтобы узнать, что у него на сайте. Панель обязана отвечать на это
 * сама.
 *
 * Три решения, которые важнее самого чтения.
 *
 * **Читаем хвост, а не файл.** Журнал растёт без предела, и `file()` на нём
 * кладёт панель по памяти ровно тогда, когда журнал и понадобился — в аварии.
 * Берём последние `TAIL_BYTES` и честно говорим, что показан хвост: «показаны
 * последние 512 КБ из 40 МБ» — это факт, а не умолчание.
 *
 * **Одинаковыми считаются только совпадающие дословно.** Соблазн свести
 * «ошибка в строке 12» и «ошибка в строке 48» в одну строку велик, но это
 * ровно то самое тихое слипание: две разные поломки показались бы одной.
 * Лишний ряд в списке дешевле спрятанной ошибки.
 *
 * **Порядок — по числу повторов, а не по времени.** Свежесть видно по колонке
 * «последний раз», а чинить нужно то, что повторяется двести раз, а не то, что
 * случилось однажды минуту назад.
 */
final class LogReader
{
    /** Сколько байт с конца файла читаем. Больше — риск положить панель. */
    private const TAIL_BYTES = 512 * 1024;

    /** Каналы, которые показываем, и их человеческие названия. */
    public const CHANNELS = [
        'error' => 'Ошибки',
        'warning' => 'Предупреждения',
        'security' => 'Безопасность',
        'app' => 'События',
        'php-error' => 'Ошибки PHP',
    ];

    /** Строка приложения: `[2026-09-10 01:23:45] ERROR: текст`. */
    private const APP_LINE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([A-Z]+):\s*(.*)$/';

    /** Строка стандартного PHP error_log на Apache/FPM. */
    private const PHP_LINE = '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Z]{2,6}))?\]\s+PHP\s+([^:]+):\s*(.*)$/';

    /**
     * @return array{date:string, level:string, message:string}|null
     */
    private static function parseLine(string $line): ?array
    {
        if (preg_match(self::APP_LINE, $line, $match) === 1) {
            return ['date' => $match[1], 'level' => $match[2], 'message' => $match[3]];
        }
        if (preg_match(self::PHP_LINE, $line, $match) !== 1) {
            return null;
        }

        $timestamp = strtotime($match[1] . (!empty($match[2]) ? ' ' . $match[2] : ''));
        if ($timestamp === false) {
            return null;
        }
        $phpLevel = strtolower(trim($match[3]));
        $level = str_contains($phpLevel, 'fatal') || str_contains($phpLevel, 'parse')
            ? 'CRITICAL'
            : (str_contains($phpLevel, 'warning') ? 'WARNING'
                : (str_contains($phpLevel, 'deprecated') ? 'DEPRECATED' : 'ERROR'));

        return [
            'date' => date('Y-m-d H:i:s', $timestamp),
            'level' => $level,
            'message' => trim($match[3]) . ': ' . $match[4],
        ];
    }

    public static function dir(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/logs';
    }

    public static function path(string $channel): string
    {
        // Имя канала уходит в путь, поэтому берём только из своего списка:
        // всё остальное — попытка прочитать чужой файл.
        return isset(self::CHANNELS[$channel]) ? self::dir() . '/' . $channel . '.log' : '';
    }

    /**
     * Сколько записей в каждом канале и когда они последний раз пополнялись.
     *
     * @return array<string, array{title:string, exists:bool, size:int, mtime:?int}>
     */
    public static function channels(): array
    {
        $out = [];
        foreach (self::CHANNELS as $channel => $title) {
            $file = self::path($channel);
            $exists = $file !== '' && is_file($file);
            $out[$channel] = [
                'title' => $title,
                'exists' => $exists,
                'size' => $exists ? (int) @filesize($file) : 0,
                'mtime' => $exists ? (@filemtime($file) ?: null) : null,
            ];
        }

        return $out;
    }

    /**
     * Сведённые записи канала.
     *
     * @return array{groups: list<array{level:string, message:string, count:int, first:string, last:string}>, total:int, size:int, truncated:bool, unparsed:int}
     */
    public static function read(string $channel, int $limit = 100): array
    {
        $file = self::path($channel);
        $empty = ['groups' => [], 'total' => 0, 'size' => 0, 'truncated' => false, 'unparsed' => 0];
        if ($file === '' || !is_file($file)) {
            return $empty;
        }

        $size = (int) @filesize($file);
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return $empty;
        }

        $truncated = $size > self::TAIL_BYTES;
        if ($truncated) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
            // Первая строка хвоста почти наверняка обрезана посередине —
            // выбрасываем её, чтобы не показать половину сообщения как запись.
            fgets($handle);
        }

        $groups = [];
        $total = 0;
        $unparsed = 0;
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                // Многострочный стек PHP или чужой формат: считаем отдельно и
                // не выдаём за разобранную запись.
                $unparsed++;
                continue;
            }

            $total++;
            $key = $parsed['level'] . "\0" . $parsed['message'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'level' => $parsed['level'],
                    'message' => $parsed['message'],
                    'count' => 0,
                    'first' => $parsed['date'],
                    'last' => $parsed['date'],
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['last'] = $parsed['date'];
        }
        fclose($handle);

        // Чинить нужно то, что повторяется, поэтому сортировка по числу
        // повторов; при равенстве — свежее выше.
        uasort($groups, static function (array $a, array $b): int {
            return [$b['count'], $b['last']] <=> [$a['count'], $a['last']];
        });

        return [
            'groups' => array_slice(array_values($groups), 0, max(1, $limit)),
            'total' => $total,
            'size' => $size,
            'truncated' => $truncated,
            'unparsed' => $unparsed,
        ];
    }

    /**
     * Сколько записей канала пришлось на последние сутки.
     *
     * Нужно разделу «Состояние системы»: там важно не что вообще случалось, а
     * что происходит сейчас.
     */
    public static function countSince(string $channel, int $seconds = 86400): int
    {
        $file = self::path($channel);
        if ($file === '' || !is_file($file)) {
            return 0;
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return 0;
        }
        if ((int) @filesize($file) > self::TAIL_BYTES) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
            fgets($handle);
        }

        // Считаем строки, а не группы: у группы своя дата только у последнего
        // повтора, и зачесть всю её в сутки значило бы показать на витрине
        // состояния число больше настоящего — то есть соврать в цифре, ради
        // которой раздел и открывают.
        $edge = date('Y-m-d H:i:s', time() - $seconds);
        $count = 0;
        while (($line = fgets($handle)) !== false) {
            $parsed = self::parseLine(rtrim($line, "\r\n"));
            if ($parsed !== null && $parsed['date'] >= $edge) {
                $count++;
            }
        }
        fclose($handle);

        return $count;
    }

    /** Очистка канала: файл остаётся на месте, содержимое обнуляется. */
    public static function clear(string $channel): bool
    {
        $file = self::path($channel);
        if ($file === '' || !is_file($file)) {
            return false;
        }

        return @file_put_contents($file, '') !== false;
    }

    /**
     * Удаляет датированные записи старше срока, не загружая файл в память.
     * Строки продолжения удаляются вместе со своей основной записью.
     *
     * @return int|null число удалённых записей; null при ошибке файла
     */
    public static function purgeOlderThan(string $channel, int $days): ?int
    {
        $file = self::path($channel);
        if ($file === '' || !is_file($file) || $days < 1 || $days > 3650) {
            return null;
        }

        $source = @fopen($file, 'c+b');
        if ($source === false || !@flock($source, LOCK_EX)) {
            if (is_resource($source)) {
                fclose($source);
            }
            return null;
        }

        $temporary = @tmpfile();
        if ($temporary === false) {
            flock($source, LOCK_UN);
            fclose($source);
            return null;
        }

        $edge = date('Y-m-d H:i:s', time() - ($days * 86400));
        $keep = true;
        $removed = 0;
        rewind($source);
        while (($line = fgets($source)) !== false) {
            $parsed = self::parseLine(rtrim($line, "\r\n"));
            if ($parsed !== null) {
                $keep = $parsed['date'] >= $edge;
                if (!$keep) {
                    $removed++;
                }
            }
            if ($keep && fwrite($temporary, $line) === false) {
                fclose($temporary);
                flock($source, LOCK_UN);
                fclose($source);
                return null;
            }
        }

        rewind($temporary);
        $written = ftruncate($source, 0) && rewind($source) && stream_copy_to_stream($temporary, $source) !== false;
        if ($written) {
            fflush($source);
        }
        fclose($temporary);
        flock($source, LOCK_UN);
        fclose($source);

        return $written ? $removed : null;
    }

    /** Размер файла словами. */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' КБ';
        }

        return round($bytes / (1024 * 1024), 1) . ' МБ';
    }
}
