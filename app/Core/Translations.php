<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;

/**
 * Единая точка доступа к языковым версиям записи.
 *
 * В CMS уживаются два механизма перевода, и это источник целого класса ошибок:
 * код, написанный под один из них, второй просто не замечает — тихо, без
 * исключений. Так двуязычная новость уходила в Telegram двумя постами, а
 * hreflang вёл на адрес, отвечающий редиректом.
 *
 *  A. Поля в таблице `{сущность}_translations`: базовая запись одна, перевод
 *     накладывается сверху. Есть у всех текстовых сущностей.
 *  B. Отдельная связанная запись (`lang` + `translation_group_id`): у каждого
 *     языка своя строка со своим slug. Есть только у новостей, страниц и
 *     проектов — там, где у языковой версии бывает собственный адрес.
 *
 * Этот класс отвечает на вопрос «какая запись отвечает за язык X» с учётом
 * обоих механизмов сразу. Новый код должен спрашивать здесь, а не собирать
 * свою логику по месту.
 */
final class Translations
{
    /**
     * Таблица переводов и её ключ на владельца. Ключ массива — таблица самой
     * сущности, она же используется в адресах админки.
     *
     * @var array<string, array{0:string|null, 1:string|null}>
     */
    private const TRANSLATION_TABLES = [
        'news' => ['news_translations', 'news_id'],
        'pages' => ['page_translations', 'page_id'],
        // У проектов своей таблицы переводов нет: языковая версия — отдельная
        // запись pages того же типа.
        'projects' => [null, null],
        'photo_albums' => ['photo_album_translations', 'album_id'],
        'videos' => ['video_translations', 'video_id'],
        'team_members' => ['team_member_translations', 'member_id'],
        'content_entries' => ['content_entry_translations', 'entry_id'],
    ];

    /** Сущности, у которых языковая версия бывает отдельной записью. */
    private const LINKED_RECORDS = ['news', 'pages', 'projects'];

    /** Порция для IN: у подготовленного запроса есть предел числа параметров. */
    private const CHUNK = 500;

    /** Поля перевода, которые не переносятся в запись: они служебные. */
    private const SERVICE_FIELDS = ['id', 'lang', 'created_at', 'updated_at'];

    public static function supportsLinkedRecords(string $table): bool
    {
        return in_array($table, self::LINKED_RECORDS, true);
    }

    /**
     * Записи по языкам: код языка → строка, которая отвечает за этот язык.
     * Связанная запись имеет приоритет над полями перевода: у неё свой адрес,
     * и именно её видит посетитель.
     *
     * @param bool $publishedOnly учитывать только опубликованные версии
     * @return array<string, array<string,mixed>>
     */
    public static function rows(string $table, int $id, bool $publishedOnly = true): array
    {
        $base = self::baseRow($table, $id);
        if ($base === null) {
            return [];
        }

        $rows = [];
        $ownLang = trim((string) ($base['lang'] ?? Language::defaultCode()));
        if ($ownLang === '') {
            $ownLang = Language::defaultCode();
        }

        // Механизм Б: связанные записи группы.
        if (self::supportsLinkedRecords($table)) {
            foreach (self::linkedRows($table, $id) as $code => $row) {
                if (!$publishedOnly || self::isPublished($row)) {
                    $rows[$code] = $row;
                }
            }
        }
        if (!isset($rows[$ownLang]) && (!$publishedOnly || self::isPublished($base))) {
            $rows[$ownLang] = $base;
        }

        // Механизм А: поля перевода поверх базовой записи. Не затираем
        // связанную запись — она полноценнее.
        foreach (self::translationRows($table, $id) as $code => $translation) {
            if (isset($rows[$code]) || $code === $ownLang) {
                continue;
            }
            if ($publishedOnly && !self::isPublished($base)) {
                continue;
            }
            $rows[$code] = self::overlay($base, $translation);
        }

        return $rows;
    }

    /**
     * То же, что rows(), но сразу для набора записей одной таблицы.
     *
     * Нужен страницам, которые перебирают сотни записей: карта сайта иначе
     * спрашивает переводы по одной. Запросов здесь три независимо от числа
     * записей — базовые строки, участники групп, строки таблицы переводов.
     *
     * Результат обязан совпадать с поштучным rows(): сверяет тест 294.
     *
     * @param array<array-key, mixed> $ids fetchAll() отдаёт нетипизированные
     *        строки, поэтому значения приводятся здесь, а не у вызывающего
     * @return array<int, array<string, array<string,mixed>>> id → язык → строка
     */
    public static function rowsBatch(string $table, array $ids, bool $publishedOnly = true): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        if ($clean === [] || !isset(self::TRANSLATION_TABLES[$table]) || !Database::isConnected()) {
            return [];
        }

        [$source, $typeWhere] = self::source($table);
        $pdo = Database::pdo();

        $bases = [];
        foreach (array_chunk($clean, self::CHUNK, true) as $chunk) {
            $stmt = $pdo->prepare(
                "SELECT * FROM {$source} WHERE id IN (" . self::marks($chunk) . "){$typeWhere}"
            );
            $stmt->execute(array_values($chunk));
            foreach ($stmt->fetchAll() as $row) {
                $bases[(int) $row['id']] = $row;
            }
        }
        if ($bases === []) {
            return array_fill_keys(array_values($clean), []);
        }

        // Механизм Б: участники групп переводов, все разом.
        $byGroup = [];
        if (self::supportsLinkedRecords($table)) {
            $groupIds = [];
            foreach ($bases as $id => $row) {
                $gid = self::groupIdOf($row, $id);
                $groupIds[$gid] = $gid;
            }
            foreach (array_chunk($groupIds, self::CHUNK) as $chunk) {
                $stmt = $pdo->prepare(
                    "SELECT * FROM {$source}
                     WHERE COALESCE(NULLIF(translation_group_id, 0), id) IN (" . self::marks($chunk) . ")
                       AND deleted_at IS NULL{$typeWhere}
                     ORDER BY id"
                );
                $stmt->execute(array_values($chunk));
                foreach ($stmt->fetchAll() as $row) {
                    $gid = self::groupIdOf($row, (int) $row['id']);
                    $lang = trim((string) ($row['lang'] ?? ''));
                    if ($lang !== '' && !isset($byGroup[$gid][$lang])) {
                        $byGroup[$gid][$lang] = $row;
                    }
                }
            }
        }

        // Механизм А: строки таблицы переводов, все разом.
        $byOwner = self::translationRowsBatch($table, $clean);

        $result = [];
        foreach ($clean as $id) {
            $base = $bases[$id] ?? null;
            if ($base === null) {
                $result[$id] = [];
                continue;
            }

            $ownLang = trim((string) ($base['lang'] ?? Language::defaultCode()));
            if ($ownLang === '') {
                $ownLang = Language::defaultCode();
            }

            $rows = [];
            foreach ($byGroup[self::groupIdOf($base, $id)] ?? [] as $code => $row) {
                if (!$publishedOnly || self::isPublished($row)) {
                    $rows[$code] = $row;
                }
            }
            if (!isset($rows[$ownLang]) && (!$publishedOnly || self::isPublished($base))) {
                $rows[$ownLang] = $base;
            }
            foreach ($byOwner[$id] ?? [] as $code => $translation) {
                if (isset($rows[$code]) || $code === $ownLang) {
                    continue;
                }
                if ($publishedOnly && !self::isPublished($base)) {
                    continue;
                }
                $rows[$code] = self::overlay($base, $translation);
            }

            $result[$id] = $rows;
        }

        return $result;
    }

    /**
     * Строки таблицы переводов для набора владельцев.
     *
     * @param array<int,int> $ids
     * @return array<int, array<string, array<string,mixed>>>
     */
    private static function translationRowsBatch(string $table, array $ids): array
    {
        [$translationTable, $ownerKey] = self::TRANSLATION_TABLES[$table];
        if ($translationTable === null || $ownerKey === null) {
            return [];
        }

        $result = [];
        foreach (array_chunk($ids, self::CHUNK, true) as $chunk) {
            try {
                $stmt = Database::pdo()->prepare(
                    "SELECT * FROM {$translationTable}
                     WHERE {$ownerKey} IN (" . self::marks($chunk) . ")
                     ORDER BY id"
                );
                $stmt->execute(array_values($chunk));
                $found = $stmt->fetchAll();
            } catch (\Throwable $e) {
                Logger::swallowed('Translations: не удалось прочитать ' . $translationTable, $e);

                return $result;
            }

            foreach ($found as $row) {
                $owner = (int) ($row[$ownerKey] ?? 0);
                $lang = trim((string) ($row['lang'] ?? ''));
                // У команды переводится имя, у остальных — заголовок.
                $headline = trim((string) ($row['title'] ?? $row['name'] ?? ''));
                if ($owner > 0 && $lang !== '' && $headline !== '') {
                    $result[$owner][$lang] = $row;
                }
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function groupIdOf(array $row, int $fallbackId): int
    {
        $group = (int) ($row['translation_group_id'] ?? 0);

        return $group > 0 ? $group : $fallbackId;
    }

    /** @param array<array-key,int> $ids */
    private static function marks(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * Языки, на которых запись реально существует.
     *
     * @return list<string>
     */
    public static function langs(string $table, int $id, bool $publishedOnly = true): array
    {
        return array_keys(self::rows($table, $id, $publishedOnly));
    }

    /**
     * Пути опубликованных языковых версий: код языка → путь без языкового
     * префикса. У связанной записи свой slug, и общий путь под чужим
     * префиксом отвечал бы редиректом.
     *
     * @return array<string,string>
     */
    public static function paths(string $table, int $id, string $prefix = ''): array
    {
        $paths = [];
        foreach (self::rows($table, $id) as $code => $row) {
            if (!empty($row['is_home'])) {
                $paths[$code] = '/';
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug !== '') {
                $paths[$code] = $prefix . $slug;
            }
        }

        return $paths;
    }

    /**
     * Запись, от имени которой действует группа: строка основного языка.
     * Публикация, счётчики и очереди должны адресоваться ей, иначе одна
     * новость учитывается дважды.
     */
    public static function primaryId(string $table, int $id): int
    {
        if (!self::supportsLinkedRecords($table)) {
            return $id;
        }
        $rows = self::rows($table, $id, false);
        $default = Language::defaultCode();

        return isset($rows[$default]['id']) ? (int) $rows[$default]['id'] : $id;
    }

    /**
     * Поля перевода поверх базовой записи. Пустое значение перевода не
     * затирает исходное: у частично заполненного перевода остальное должно
     * читаться на основном языке, а не исчезать.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $translation
     * @return array<string,mixed>
     */
    private static function overlay(array $base, array $translation): array
    {
        // Здесь список полей не задан — берём всё, что перевод и базовая
        // строка называют одинаково, кроме служебных колонок. Само правило
        // «непустое переведённое побеждает» одно на весь проект.
        $fields = array_values(array_diff(
            array_intersect(array_keys($translation), array_keys($base)),
            self::SERVICE_FIELDS
        ));

        return self::overlayFields($base, $translation, $fields);
    }

    /**
     * Физическая таблица и условие подтипа: проект — строка pages с
     * entity_type='project'.
     *
     * @return array{0:string,1:string}
     */
    private static function source(string $table): array
    {
        return match ($table) {
            'projects' => ['pages', " AND entity_type = 'project'"],
            'pages' => ['pages', " AND entity_type = 'page'"],
            default => [$table, ''],
        };
    }

    /** @return array<string,mixed>|null */
    private static function baseRow(string $table, int $id): ?array
    {
        if ($id <= 0 || !isset(self::TRANSLATION_TABLES[$table]) || !Database::isConnected()) {
            return null;
        }
        [$source, $typeWhere] = self::source($table);
        $stmt = Database::pdo()->prepare("SELECT * FROM {$source} WHERE id = :id{$typeWhere} LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Записи группы переводов по языкам. Первая запись языка выигрывает:
     * дубли в группе — редкость, но порядок должен быть предсказуемым.
     *
     * @return array<string, array<string,mixed>>
     */
    private static function linkedRows(string $table, int $id): array
    {
        $pdo = Database::pdo();
        [$source, $typeWhere] = self::source($table);
        $stmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(translation_group_id, 0), id) FROM {$source} WHERE id = :id{$typeWhere} LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $groupId = (int) ($stmt->fetchColumn() ?: $id);

        $stmtGroup = $pdo->prepare(
            "SELECT * FROM {$source}
             WHERE COALESCE(NULLIF(translation_group_id, 0), id) = :group_id
               AND deleted_at IS NULL{$typeWhere}
             ORDER BY id"
        );
        $stmtGroup->execute([':group_id' => $groupId]);

        $rows = [];
        foreach ($stmtGroup->fetchAll() as $row) {
            $lang = trim((string) ($row['lang'] ?? ''));
            if ($lang !== '' && !isset($rows[$lang])) {
                $rows[$lang] = $row;
            }
        }

        return $rows;
    }

    /**
     * Строки таблицы переводов по языкам. Перевод без заголовка не считается
     * переводом: иначе пустая строка выдавала бы язык за существующий.
     *
     * @return array<string, array<string,mixed>>
     */
    private static function translationRows(string $table, int $id): array
    {
        [$translationTable, $ownerKey] = self::TRANSLATION_TABLES[$table];
        if ($translationTable === null || $ownerKey === null) {
            return [];
        }

        try {
            $stmt = Database::pdo()->prepare(
                "SELECT * FROM {$translationTable} WHERE {$ownerKey} = :id"
            );
            $stmt->execute([':id' => $id]);
            $found = $stmt->fetchAll();
        } catch (\Throwable $e) {
            Logger::swallowed('Translations: не удалось прочитать ' . $translationTable, $e);

            return [];
        }

        $rows = [];
        foreach ($found as $row) {
            $lang = trim((string) ($row['lang'] ?? ''));
            // У команды переводится имя, у остальных — заголовок.
            $headline = trim((string) ($row['title'] ?? $row['name'] ?? ''));
            if ($lang !== '' && $headline !== '') {
                $rows[$lang] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private static function isPublished(array $row): bool
    {
        if (!empty($row['deleted_at'])) {
            return false;
        }
        // Сущности без статуса (альбомы, видео) считаются опубликованными:
        // видимость у них определяется другими признаками.
        if (!array_key_exists('status', $row)) {
            return true;
        }

        return (string) $row['status'] === 'published';
    }

    /**
     * Наложение перевода на базовую строку (механизм А).
     *
     * Правило одно на все сущности: непустое переведённое значение побеждает,
     * пустое — уступает базовому языку. Оно было записано по разу в каждой
     * модели (`applyTranslation`) плюс здесь, различаясь только тем, откуда
     * берётся список полей. Такие копии расходятся молча: у одной сущности
     * «пусто» значило бы строку из пробелов, у другой — отсутствие ключа.
     *
     * @param array<string,mixed> $row базовая строка
     * @param array<string,mixed>|null $translation строка перевода или null
     * @param list<string> $fields переводимые поля
     * @param bool $trimBlank считать ли строку из одних пробелов пустой
     * @return array<string,mixed>
     */
    public static function overlayFields(
        array $row,
        ?array $translation,
        array $fields,
        bool $trimBlank = true
    ): array {
        if ($translation === null) {
            return $row;
        }

        foreach ($fields as $field) {
            if (!isset($translation[$field])) {
                continue;
            }
            $value = $translation[$field];
            $blank = $trimBlank ? trim((string) $value) === '' : $value === '';
            if (!$blank) {
                $row[$field] = $value;
            }
        }

        return $row;
    }

    /**
     * Языки, на которых у записей есть контент (механизм А), одним запросом.
     *
     * Отвечает колонке «Языки» в админских списках. Метод повторялся в моделях
     * дословно — полсотни строк на сущность, включая оба `try/catch` и условие
     * «непуст хотя бы один переводимый столбец»; отличались только имена
     * таблиц. Имя таблицы переводов и ключ на владельца берутся из
     * `TRANSLATION_TABLES` — из того же места, что и остальные ответы класса,
     * а не из третьей копии этого знания.
     *
     * Основной язык добавляется, только если базовая запись заполнена: пустая
     * строка версией на языке не является.
     *
     * @param array<int|string> $ids
     * @param list<string> $columns переводимые столбцы; первый служит признаком
     *                              заполненности базовой записи
     * @return array<int, list<string>>
     */
    public static function availableLangs(string $table, array $ids, array $columns): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = [];
        }
        if ($ids === [] || $columns === []) {
            return $map;
        }

        [$translationTable, $foreignKey] = self::TRANSLATION_TABLES[$table] ?? [null, null];
        if ($translationTable === null || $foreignKey === null) {
            throw new \InvalidArgumentException('у таблицы ' . $table . ' нет таблицы переводов');
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $default = Language::defaultCode();
        $baseColumn = $columns[0];

        try {
            $stmt = Database::pdo()->prepare("SELECT id, {$baseColumn} FROM {$table} WHERE id IN ({$in})");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row['id'];
                if (isset($map[$id]) && trim((string) ($row[$baseColumn] ?? '')) !== '') {
                    $map[$id][] = $default;
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed(
                'Translations::availableLangs(' . $table . '): не удалось прочитать базовые записи',
                $e
            );
        }

        $filled = implode(' OR ', array_map(
            static fn (string $column): string => "TRIM(COALESCE({$column}, '')) <> ''",
            $columns
        ));

        try {
            $stmt = Database::pdo()->prepare(
                "SELECT {$foreignKey}, lang FROM {$translationTable}
                  WHERE {$foreignKey} IN ({$in}) AND ({$filled})"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row[$foreignKey];
                $lang = (string) $row['lang'];
                if (isset($map[$id]) && !in_array($lang, $map[$id], true)) {
                    $map[$id][] = $lang;
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed(
                'Translations::availableLangs(' . $table . '): не удалось прочитать ' . $translationTable,
                $e
            );
        }

        // Запись без единого заполненного поля всё равно числится на основном
        // языке: иначе в списке админки у неё не было бы ни одной метки.
        foreach ($ids as $id) {
            if ($map[$id] === []) {
                $map[$id] = [$default];
            }
        }

        return $map;
    }
}
