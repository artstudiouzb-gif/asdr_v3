<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Где используется файл медиатеки.
 *
 * Прежде ответ давал ручной список из восьми таблиц, и файл, стоящий в
 * слайде обложки, в альбоме, в видео, у цели, в виджете, в записи каталога
 * или логотипом в конструкторе шапки (там он лежит внутри JSON, а сверка
 * шла точным совпадением), считался свободным: его можно было удалить, и
 * страница ломалась молча. Поэтому обход идёт по **всем** текстовым колонкам
 * базы (`ContentLocator`), а исключения перечислены поимённо: новая таблица
 * контента попадает в проверку сама, а не ждёт, пока о ней вспомнят.
 *
 * Упоминание узнаётся по основе имени — без расширения и суффикса
 * уменьшенной копии (`имя-400.webp` — это тот же файл), защищённый файл —
 * по номеру в ссылке `download.php?file_id=N`: имени в ней нет. Одно и то же
 * правило (`referencesIn`) решает и для одного файла, и для прохода по всей
 * медиатеке, иначе фильтр «не используется» и запрет удаления разошлись бы.
 */
final class MediaUsage
{
    /**
     * Места, где упомянут файл медиатеки.
     *
     * @param array<string, mixed> $file строка `files`
     * @return list<array{label: string, url: string, trashed: bool, table: string}>
     */
    public static function find(array $file): array
    {
        return ContentLocator::describe(self::hits($file));
    }

    /**
     * Число строк базы, где упомянут файл (одна запись с тремя блоками даёт
     * три). Нужно очистке сирот: ей важно «есть ли хоть одно», а не «где».
     *
     * @param array<string, mixed> $file
     */
    public static function mentions(array $file): int
    {
        return count(self::hits($file));
    }

    /**
     * @param array<string, mixed> $file
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private static function hits(array $file): array
    {
        $key = self::keyOf($file);
        if ($key === '') {
            return [];
        }
        $needle = str_starts_with($key, '#') ? 'file_id=' . substr($key, 1) : self::needleOf($key);

        $hits = [];
        foreach (ContentLocator::columns() as $table => $columns) {
            foreach (self::scan($table, $columns, $needle) as $row) {
                if (in_array($key, self::referencesIn(ContentLocator::rowText($row, $columns)), true)) {
                    $hits[] = [$table, $row];
                }
            }
        }

        return $hits;
    }

    /**
     * Число мест, где упомянут файл.
     *
     * @param array<string, mixed> $file
     */
    public static function count(array $file): int
    {
        return count(self::find($file));
    }

    /**
     * Ключи всех файлов, упомянутых хоть где-то, — одним проходом по базе.
     * Нужен фильтру «Не используется»: спрашивать каждый файл отдельно значит
     * сотни проходов на одну страницу списка.
     *
     * @return array<string, true>
     */
    public static function referencedKeys(): array
    {
        $keys = [];
        foreach (ContentLocator::columns() as $table => $columns) {
            $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
            $stmt = Database::pdo()->query('SELECT ' . $select . ' FROM `' . $table . '`');
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                foreach (self::referencesIn(ContentLocator::rowText($row, $columns)) as $ref) {
                    $keys[$ref] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Ключ файла: основа имени для открытого, «#id» для защищённого.
     *
     * @param array<string, mixed> $file
     */
    public static function keyOf(array $file): string
    {
        if (($file['access_type'] ?? 'public') === 'protected') {
            return isset($file['id']) ? '#' . (int) $file['id'] : '';
        }

        return self::stemOf((string) ($file['stored_name'] ?? ''));
    }

    /**
     * Основа имени: без расширения и без суффикса уменьшенной копии.
     */
    public static function stemOf(string $name): string
    {
        $path = parse_url($name, PHP_URL_PATH);
        $name = basename(str_replace('\\', '/', is_string($path) ? $path : $name));
        foreach (Media::variantSuffixes() as $suffix) {
            if ($suffix !== '.webp' && str_ends_with($name, $suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return (string) preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name);
    }

    /**
     * Ключи файлов, упомянутых в тексте: имя после «/» с расширением и
     * номер в ссылке на защищённый файл. Экранирование JSON (`\/`, `&`)
     * снимается заранее — так хранятся данные блоков и конструкторов.
     *
     * @return list<string>
     */
    public static function referencesIn(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $text = str_replace(['\\/', '\\u0026', '&amp;'], ['/', '&', '&'], $text);
        $refs = [];
        if (preg_match_all('~/([A-Za-z0-9_.-]+\.[A-Za-z0-9]{1,8})(?![A-Za-z0-9_-])~', $text, $m)) {
            foreach ($m[1] as $name) {
                $refs[self::stemOf($name)] = true;
            }
        }
        if (preg_match_all('~[?&]file_id=(\d+)(?!\d)~', $text, $m)) {
            foreach ($m[1] as $id) {
                $refs['#' . (int) $id] = true;
            }
        }

        return array_map('strval', array_keys($refs));
    }

    /**
     * Строки таблицы, где хоть одна колонка содержит подстроку. LIKE —
     * только грубый отбор, окончательно решает `referencesIn`.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private static function scan(string $table, array $columns, string $needle): array
    {
        $pdo = Database::pdo();
        $select = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
        foreach (ContentLocator::locatorColumns($table) as $extra) {
            if (!in_array($extra, $columns, true)) {
                $select[] = '`' . $extra . '`';
            }
        }
        // Позиционные параметры: без эмуляции PDO не даёт повторить именованный.
        $where = implode(' OR ', array_map(static fn (string $c): string => '`' . $c . '` LIKE ?', $columns));
        $stmt = $pdo->prepare('SELECT ' . implode(', ', array_unique($select)) . ' FROM `' . $table . '` WHERE ' . $where);
        $stmt->execute(array_fill(0, count($columns), '%' . addcslashes($needle, '%_\\') . '%'));

        return Database::rows($stmt);
    }

    /**
     * Подстрока для грубого отбора. Основа имени у загрузок — «слаг-6hex»,
     * то есть достаточно редкая; совсем короткую ищем с «/» впереди, иначе
     * отбор вернул бы полбазы.
     */
    private static function needleOf(string $stem): string
    {
        return strlen($stem) < 6 ? '/' . $stem : $stem;
    }

}
