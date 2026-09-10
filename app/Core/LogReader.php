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

    /** Строка журнала: `[2026-09-10 01:23:45] ERROR: текст`. */
    private const LINE = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([A-Z]+):\s*(.*)$/';

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
            if (preg_match(self::LINE, $line, $m) !== 1) {
                // Многострочный стек PHP или чужой формат: считаем отдельно и
                // не выдаём за разобранную запись.
                $unparsed++;
                continue;
            }

            $total++;
            $key = $m[2] . "\0" . $m[3];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'level' => $m[2],
                    'message' => $m[3],
                    'count' => 0,
                    'first' => $m[1],
                    'last' => $m[1],
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['last'] = $m[1];
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
            if (preg_match(self::LINE, rtrim($line, "\r\n"), $m) === 1 && $m[1] >= $edge) {
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
