<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\BlockRevision;
use App\Models\Setting;

/**
 * Найти и заменить фразу по всему контенту сайта.
 *
 * Где лежит контент, решает `ContentLocator` — тот же ответ, что у «где
 * используется файл» и «проверки ссылок»: третий список таблиц разъехался
 * бы с ними при первой новой таблице. Замена ходит только по таблицам с
 * известным владельцем (`isContentTable`): неизвестная таблица — служебные
 * данные, о которых решение не принято.
 *
 * Три правила, ради которых замена не строка `str_replace` по базе:
 * - **Данные блока — JSON.** Замена в сырой строке задела бы ключи и
 *   экранирование (`\"`), поэтому JSON разбирается, меняются только
 *   текстовые значения, а значения технических ключей (ссылка, иконка,
 *   раскладка, цвет) не трогаются вовсе: слово «stack» в тексте и
 *   раскладка `stack` — разные вещи.
 * - **В HTML меняется только текст, а не теги.** Иначе фраза в адресе
 *   ссылки или в имени класса ломала бы разметку.
 * - **Пробел в фразе совпадает с любым пробелом**, включая неразрывный и
 *   `&nbsp;`: типограф ставит их после предлогов, и «в Агентстве» в базе
 *   почти всегда записано не тем пробелом, который набирают в поиске.
 *
 * Замена идёт по отмеченным местам, а не «везде сразу», и каждая операция
 * пишет журнал отмены (`storage/text-replace`): прежнее значение
 * возвращается, только если поле с тех пор никто не правил. У блоков
 * вдобавок остаётся обычная версия в истории блока.
 */
final class TextReplace
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 200;

    /** Больше мест в одном ответе не показываем: это уже не правка, а перепись. */
    public const MAX_HITS = 300;

    /** Сколько последних операций держит журнал отмены. */
    public const KEEP_LOG = 30;

    /** Таблицы контента, где фраза — не текст, а адрес или код языка. */
    private const SKIP_TABLES = ['redirects', 'languages'];

    /**
     * Колонка или ключ JSON, значение которого — не текст для читателя:
     * адрес, имя файла, тип, раскладка, цвет, служебный код. Решает
     * окончание имени, поэтому `card_style`, `title_level` и `video_url`
     * узнаются без отдельной строки на каждое поле. «Положение» техническое
     * только с приставкой (`icon_position`): голое `position` — это должность
     * сотрудника.
     */
    private const TECHNICAL = '/(^|_)(slug|url|urls|href|link|path|key|type|lang|status|section|hash|token|secret|password|css|js|class|icon|svg|image|images|photo|file|files|video|poster|logo|email|ip|mime|color|colour|bg|layout|variant|style|align|scheme|size|ratio|columns|cols|mode|order|target|anchor|tag|level|tone|side|width|height|speed|gap|corner|center|spin|format|provider|embed|map|lat|lng|date|time|at|id)$|_position$/';

    /** Настройка с секретом или служебным значением — не трогаем никогда. */
    private const SECRET_SETTING = '/(token|secret|password|passwd|api|smtp|totp|webhook|cloudflare|cdn|_key$|_id$)/';

    /** Значение-адрес, путь или цвет внутри JSON. */
    private const NOT_TEXT_VALUE = '/^(?:https?:|mailto:|tel:|\/|#[0-9a-fA-F]{3,8}$)/';

    /**
     * Регулярка фразы: пробел совпадает с любым пробелом, кавычки и спецсимволы
     * — буквально.
     */
    public static function pattern(string $needle, bool $caseSensitive): string
    {
        $words = preg_split('/\s+/u', trim($needle)) ?: [];
        $quoted = array_map(static fn (string $w): string => preg_quote($w, '~'), $words);

        return '~' . implode('(?:\s|\x{00A0}|&nbsp;|&#160;)+', $quoted) . '~u' . ($caseSensitive ? '' : 'i');
    }

    /**
     * Места, где встречается фраза.
     *
     * @return array{hits: list<array{ref: string, table: string, column: string, count: int, label: string, url: string, trashed: bool, before: string, match: string, after: string}>, truncated: bool}
     */
    public static function find(string $needle, bool $caseSensitive, bool $withSettings): array
    {
        $pattern = self::pattern($needle, $caseSensitive);
        $terms = self::prefilterTerms($needle);
        $pdo = Database::pdo();
        $hits = [];
        $truncated = false;
        $labels = [];

        foreach (self::targets($withSettings) as $table => $columns) {
            $pk = ContentLocator::primaryKey($pdo, $table);
            if ($pk === null) {
                continue;
            }
            $where = [];
            $params = [];
            foreach ($columns as $column) {
                foreach ($terms as $term) {
                    $where[] = 'LOWER(CAST(`' . $column . '` AS CHAR)) LIKE ?';
                    $params[] = $term;
                }
            }
            $select = array_values(array_unique(array_merge(ContentLocator::locatorColumns($table), $columns)));
            $stmt = $pdo->prepare('SELECT `' . implode('`, `', $select) . '` FROM `' . $table . '` WHERE ' . implode(' OR ', $where)
                . ' ORDER BY `' . $pk . '` LIMIT ' . (self::MAX_HITS + 1));
            $stmt->execute($params);

            foreach (Database::rows($stmt) as $row) {
                if ($table === 'settings' && !self::settingAllowed((string) ($row['key'] ?? ''))) {
                    continue;
                }
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    $count = 0;
                    self::replaceIn($value, $pattern, '', $count);
                    if ($count === 0) {
                        continue;
                    }
                    if (count($hits) >= self::MAX_HITS) {
                        $truncated = true;
                        break 3;
                    }
                    $placeKey = $table . ':' . (string) $row[$pk];
                    $labels[$placeKey] ??= self::place($table, $row);
                    $hits[] = ['ref' => $table . '|' . (string) $row[$pk] . '|' . $column, 'table' => $table, 'column' => $column, 'count' => $count]
                        + $labels[$placeKey]
                        + self::snippet($value, $pattern);
                }
            }
        }

        return ['hits' => $hits, 'truncated' => $truncated];
    }

    /**
     * Заменяет фразу в отмеченных местах. Ссылка места проверяется заново:
     * она пришла из формы, а не из нашего поиска.
     *
     * @param list<string> $refs
     * @return array{fields: int, replacements: int, log: string}
     */
    public static function apply(string $needle, string $replacement, bool $caseSensitive, array $refs, ?int $userId): array
    {
        $pattern = self::pattern($needle, $caseSensitive);
        $targets = self::targets(true);
        $changes = [];
        $total = 0;

        Database::transaction(static function (\PDO $pdo) use ($refs, $targets, $pattern, $replacement, $userId, &$changes, &$total): void {
            foreach (array_unique($refs) as $ref) {
                $parts = explode('|', $ref, 3);
                if (count($parts) !== 3) {
                    continue;
                }
                [$table, $id, $column] = $parts;
                if (!in_array($column, $targets[$table] ?? [], true)) {
                    continue;
                }
                $pk = ContentLocator::primaryKey($pdo, $table);
                if ($pk === null) {
                    continue;
                }
                $extra = $table === 'blocks' ? ', `title`, `type`, `custom_css`' : ($table === 'settings' ? ', `key`' : '');
                $stmt = $pdo->prepare('SELECT `' . $column . '`' . $extra . ' FROM `' . $table . '` WHERE `' . $pk . '` = ? FOR UPDATE');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!is_array($row) || !is_string($row[$column] ?? null)) {
                    continue;
                }
                if ($table === 'settings' && !self::settingAllowed((string) ($row['key'] ?? ''))) {
                    continue;
                }
                $old = $row[$column];
                $count = 0;
                $new = self::replaceIn($old, $pattern, $replacement, $count);
                if ($count === 0 || $new === $old) {
                    continue;
                }
                if ($table === 'blocks' && $column === 'data') {
                    // Прежняя версия остаётся в истории блока — откат оттуда
                    // работает так же, как после правки формой.
                    $previous = json_decode($old, true);
                    BlockRevision::snapshot(
                        (int) $id,
                        $row['title'] !== null ? (string) $row['title'] : null,
                        is_array($previous) ? $previous : [],
                        $row['custom_css'] !== null ? (string) $row['custom_css'] : null,
                        $userId,
                        (string) $row['type']
                    );
                }
                self::write($pdo, $table, $pk, $id, $column, $new);
                $changes[] = ['table' => $table, 'id' => $id, 'column' => $column, 'old' => $old, 'new' => $new];
                $total += $count;
            }
        });

        $log = $changes !== [] ? self::saveLog($needle, $replacement, $userId, $changes) : '';
        if ($changes !== []) {
            self::afterChange();
        }

        return ['fields' => count($changes), 'replacements' => $total, 'log' => $log];
    }

    /**
     * Отмена операции: поле возвращается к прежнему значению, только если с
     * тех пор его никто не менял — иначе отмена затёрла бы чужую правку.
     *
     * @return array{restored: int, conflicts: int}|null
     */
    public static function undo(string $logId): ?array
    {
        $path = self::logPath($logId);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $log = json_decode((string) file_get_contents($path), true);
        if (!is_array($log) || !empty($log['undone']) || !is_array($log['changes'] ?? null)) {
            return null;
        }
        $targets = self::targets(true);
        $restored = 0;
        $conflicts = 0;

        Database::transaction(static function (\PDO $pdo) use ($log, $targets, &$restored, &$conflicts): void {
            foreach ($log['changes'] as $change) {
                $table = (string) ($change['table'] ?? '');
                $column = (string) ($change['column'] ?? '');
                $pk = in_array($column, $targets[$table] ?? [], true) ? ContentLocator::primaryKey($pdo, $table) : null;
                if ($pk === null) {
                    $conflicts++;
                    continue;
                }
                $stmt = $pdo->prepare('SELECT `' . $column . '` FROM `' . $table . '` WHERE `' . $pk . '` = ? FOR UPDATE');
                $stmt->execute([(string) $change['id']]);
                $current = $stmt->fetchColumn();
                if (!is_string($current) || $current !== (string) $change['new']) {
                    $conflicts++;
                    continue;
                }
                self::write($pdo, $table, $pk, (string) $change['id'], $column, (string) $change['old']);
                $restored++;
            }
        });

        $log['undone'] = date('Y-m-d H:i:s');
        file_put_contents($path, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($restored > 0) {
            self::afterChange();
        }

        return ['restored' => $restored, 'conflicts' => $conflicts];
    }

    /**
     * Последние операции, новые сверху.
     *
     * @return list<array{id: string, at: string, needle: string, replacement: string, fields: int, undone: string}>
     */
    public static function history(): array
    {
        $files = glob(self::logDir() . '/*.json') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, self::KEEP_LOG) as $file) {
            $log = json_decode((string) file_get_contents($file), true);
            if (!is_array($log)) {
                continue;
            }
            $out[] = [
                'id' => basename($file, '.json'),
                'at' => (string) ($log['at'] ?? ''),
                'needle' => (string) ($log['needle'] ?? ''),
                'replacement' => (string) ($log['replacement'] ?? ''),
                'fields' => is_array($log['changes'] ?? null) ? count($log['changes']) : 0,
                'undone' => (string) ($log['undone'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Замена в одном значении: JSON — по текстовым значениям, остальное — как
     * текст. `$count` получает число замен (при пустой замене это и есть
     * число совпадений — так считает поиск).
     */
    public static function replaceIn(string $value, string $pattern, string $replacement, int &$count): string
    {
        $first = $value[0] ?? '';
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $before = $count;
                $walked = self::walk($decoded, $pattern, $replacement, $count, '');
                if ($count === $before) {
                    return $value;
                }

                return json_encode($walked, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: $value;
            }
        }

        return self::replaceText($value, $pattern, $replacement, $count);
    }

    private static function walk(mixed $node, string $pattern, string $replacement, int &$count, string $key): mixed
    {
        if (is_array($node)) {
            foreach ($node as $k => $child) {
                // У списка ключ — номер, техническим он не бывает: решает
                // имя поля, в котором список лежит.
                $node[$k] = self::walk($child, $pattern, $replacement, $count, is_string($k) ? $k : $key);
            }

            return $node;
        }
        if (!is_string($node) || $node === '' || self::isTechnical($key) || preg_match(self::NOT_TEXT_VALUE, $node) === 1) {
            return $node;
        }

        return self::replaceText($node, $pattern, $replacement, $count);
    }

    /** В HTML меняется только текст между тегами. */
    private static function replaceText(string $text, string $pattern, string $replacement, int &$count): string
    {
        $parts = str_contains($text, '<')
            ? (preg_split('/(<[^>]*>)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text])
            : [$text];
        foreach ($parts as $i => $part) {
            if ($part === '' || ($part[0] === '<' && str_ends_with($part, '>'))) {
                continue;
            }
            $n = 0;
            $parts[$i] = preg_replace_callback($pattern, static fn (): string => $replacement, $part, -1, $n) ?? $part;
            $count += $n;
        }

        return implode('', $parts);
    }

    private static function isTechnical(string $name): bool
    {
        // Ключи оформления блока (`_bg`, `_reveal`, …) — настройки, кроме двух
        // текстовых: подзаголовка шапки и фоновой надписи.
        if (str_starts_with($name, '_')) {
            return !in_array($name, ['_head_kicker', '_watermark'], true);
        }

        return preg_match(self::TECHNICAL, $name) === 1;
    }

    private static function settingAllowed(string $key): bool
    {
        return $key !== '' && preg_match(self::SECRET_SETTING, $key) !== 1;
    }

    /**
     * Таблица => текстовые колонки, где ищем.
     *
     * @return array<string, list<string>>
     */
    private static function targets(bool $withSettings): array
    {
        $out = [];
        foreach (ContentLocator::columns() as $table => $columns) {
            if (!ContentLocator::isContentTable($table) || in_array($table, self::SKIP_TABLES, true)) {
                continue;
            }
            if ($table === 'settings' && !$withSettings) {
                continue;
            }
            $text = array_values(array_filter(
                $columns,
                static fn (string $c): bool => $table === 'settings' ? $c === 'value' : !self::isTechnical($c)
            ));
            if ($text !== []) {
                $out[$table] = $text;
            }
        }

        return $out;
    }

    /**
     * Грубый отбор строк в базе: самое длинное слово фразы. Точное совпадение
     * (с любым пробелом и регистром) проверяет уже регулярка.
     *
     * @return list<string>
     */
    private static function prefilterTerms(string $needle): array
    {
        $words = preg_split('/\s+/u', trim($needle)) ?: [''];
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $word = mb_strtolower($words[0]);
        $escaped = trim(json_encode($word, JSON_INVALID_UTF8_SUBSTITUTE) ?: $word, '"');
        $like = static fn (string $w): string => '%' . strtr($w, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';

        return array_values(array_unique([$like($word), $like($escaped)]));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{label: string, url: string, trashed: bool}
     */
    private static function place(string $table, array $row): array
    {
        $described = ContentLocator::describe([[$table, $row]]);
        $first = $described[0] ?? ['label' => $table, 'url' => '', 'trashed' => false];

        return ['label' => $first['label'], 'url' => $first['url'], 'trashed' => $first['trashed']];
    }

    /**
     * Кусок текста вокруг первого совпадения — простым текстом, без разметки.
     *
     * @return array{before: string, match: string, after: string}
     */
    private static function snippet(string $value, string $pattern): array
    {
        $texts = [];
        $decoded = ($value[0] ?? '') === '{' || ($value[0] ?? '') === '[' ? json_decode($value, true) : null;
        if (is_array($decoded)) {
            array_walk_recursive($decoded, static function (mixed $leaf) use (&$texts): void {
                if (is_string($leaf)) {
                    $texts[] = $leaf;
                }
            });
        } else {
            $texts[] = $value;
        }
        foreach ($texts as $text) {
            $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));
            if (preg_match($pattern, $plain, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $start = mb_strlen(substr($plain, 0, $m[0][1]));
            $length = mb_strlen($m[0][0]);
            $from = max(0, $start - 60);

            return [
                'before' => ($from > 0 ? '…' : '') . mb_substr($plain, $from, $start - $from),
                'match' => $m[0][0],
                'after' => mb_substr($plain, $start + $length, 60) . (mb_strlen($plain) > $start + $length + 60 ? '…' : ''),
            ];
        }

        return ['before' => '', 'match' => '', 'after' => ''];
    }

    private static function write(\PDO $pdo, string $table, string $pk, string $id, string $column, string $value): void
    {
        $bump = $table === 'blocks' ? ', `lock_version` = `lock_version` + 1' : '';
        $pdo->prepare('UPDATE `' . $table . '` SET `' . $column . '` = ?' . $bump . ' WHERE `' . $pk . '` = ?')
            ->execute([$value, $id]);
    }

    private static function afterChange(): void
    {
        Setting::forgetCache();
        Cache::forgetPrefix('page:');
        Cache::flush();
    }

    /** @param list<array{table: string, id: string, column: string, old: string, new: string}> $changes */
    private static function saveLog(string $needle, string $replacement, ?int $userId, array $changes): string
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        file_put_contents($dir . '/' . $id . '.json', json_encode([
            'at' => date('Y-m-d H:i:s'),
            'user' => $userId,
            'needle' => $needle,
            'replacement' => $replacement,
            'changes' => $changes,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);

        $files = glob($dir . '/*.json') ?: [];
        rsort($files);
        foreach (array_slice($files, self::KEEP_LOG) as $old) {
            @unlink($old);
        }

        return $id;
    }

    private static function logDir(): string
    {
        return APP_ROOT . '/storage/text-replace';
    }

    /** Имя операции приходит из формы: в путь идёт только своё имя. */
    private static function logPath(string $id): ?string
    {
        return preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id) === 1 ? self::logDir() . '/' . $id . '.json' : null;
    }
}
