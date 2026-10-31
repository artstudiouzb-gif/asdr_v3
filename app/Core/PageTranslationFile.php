<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Block;
use App\Models\BlockSnippet;
use InvalidArgumentException;

/**
 * Перевод всех страниц одним файлом: выгрузка текстов одного языка в
 * таблицу и загрузка переведённой таблицы языковыми версиями страниц.
 *
 * Переводчику нужен текст, а не конструктор: открывать сорок страниц и
 * переписывать каждый блок в форме — это дни работы и гарантированно
 * пропущенные поля. Таблица несёт по строке на каждое текстовое поле —
 * заголовок, лид и SEO страницы, тексты блоков вплоть до элемента
 * репитера, — а переводчик заполняет один столбец.
 *
 * **Что текст, решает то же правило, что у «Найти и заменить»**
 * (`TextReplace::isReaderText`): ссылки, иконки, раскладки и цвета в
 * таблицу не попадают, а значит и сломать их переводом нельзя.
 *
 * **Загрузка собирает перевод из исходника, а не правит перевод на месте.**
 * Блоки языковой версии — это копия блоков исходной страницы, в которой
 * тексты заменены переводом: так сопоставление строк таблицы с полями не
 * зависит от того, что за это время делали с переводом. Ключ строки —
 * номер блока исходника и путь к полю внутри его данных.
 *
 * **Изменившийся исходник не переводится вслепую.** Таблица несёт текст,
 * с которого делался перевод; если на сайте он с тех пор другой, строка
 * пропускается и попадает в отчёт — перевод старого абзаца поверх нового
 * был бы неправдой, которую никто не заметит.
 *
 * **Готовый перевод не затирается молча.** Языковая версия, у которой уже
 * есть блоки, перезаписывается только с явной галочкой, и перед заменой её
 * блоки уходят автокопией в библиотеку шаблонов.
 */
final class PageTranslationFile
{
    /** Служебная строка таблицы: по ней загрузка узнаёт пару языков. */
    private const META_PREFIX = '#languages:';

    /** Поля записи страницы, которые переводятся. */
    private const PAGE_FIELDS = [
        'title' => 'Заголовок страницы',
        'lead' => 'Лид (вводный текст)',
        'meta_title' => 'SEO: заголовок',
        'meta_description' => 'SEO: описание',
    ];

    /** Блоки, тексты которых не переводятся: «HTML» — это код, а не текст. */
    private const SKIP_TYPES = ['html'];

    /** Больше строк в таблице не принимаем: это уже не перевод сайта, а ошибка файла. */
    public const MAX_ROWS = 20000;

    /**
     * Таблица текстов всех страниц и проектов языка $from.
     *
     * @return list<list<string>>
     */
    public static function rows(string $from, string $to): array
    {
        $rows = [[
            'Ключ (не менять)',
            'Страница',
            'Поле',
            'Исходный текст (' . strtoupper($from) . ')',
            'Перевод (' . strtoupper($to) . ')',
        ], [self::META_PREFIX . $from . '>' . $to, 'Служебная строка — не удалять', '', '', '']];

        foreach (self::sourcePages($from) as $page) {
            $label = self::pageLabel($page);
            foreach (self::PAGE_FIELDS as $field => $fieldLabel) {
                $value = trim((string) ($page[$field] ?? ''));
                if ($value !== '') {
                    $rows[] = ['page:' . (int) $page['id'] . ':' . $field, $label, $fieldLabel, $value, ''];
                }
            }
            foreach (self::blockTree((int) $page['id'], $from) as $block) {
                foreach (self::blockTexts($block) as [$key, $fieldLabel, $value]) {
                    $rows[] = [$key, $label, $fieldLabel, $value, ''];
                }
            }
        }

        return $rows;
    }

    /** Файл XLSX для скачивания. */
    public static function export(string $from, string $to): string
    {
        return Xlsx::write(self::rows($from, $to), [22, 30, 34, 70, 70]);
    }

    public static function fileName(string $from, string $to): string
    {
        return 'perevod-stranic-' . $from . '-' . $to . '-' . date('Y-m-d') . '.xlsx';
    }

    /**
     * Загрузка переведённой таблицы.
     *
     * @param list<list<string>> $rows строки таблицы, как их вернул Xlsx::read
     * @param list<string> $activeLangs коды языков сайта
     * @return array{created: int, updated: int, skipped_existing: list<string>, untranslated: int, stale: int, unknown: int, pages: list<string>}
     * @throws InvalidArgumentException с текстом для редактора
     */
    public static function import(array $rows, array $activeLangs, bool $overwrite): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('В таблице больше ' . self::MAX_ROWS . ' строк — это не файл перевода страниц.');
        }
        [$from, $to] = self::languages($rows);
        if (!in_array($from, $activeLangs, true) || !in_array($to, $activeLangs, true) || $from === $to) {
            throw new InvalidArgumentException('Пара языков в файле (' . $from . ' → ' . $to . ') не подходит этому сайту.');
        }

        // Строки по страницам: ключ → [исходный текст, перевод].
        $byPage = [];
        $owners = [];
        $unknown = 0;
        foreach (array_slice($rows, 1) as $row) {
            $key = trim((string) ($row[0] ?? ''));
            if ($key === '' || str_starts_with($key, self::META_PREFIX)) {
                continue;
            }
            $pageId = self::pageIdOf($key, $owners);
            if ($pageId === null) {
                $unknown++;
                continue;
            }
            $byPage[$pageId][$key] = [(string) ($row[3] ?? ''), (string) ($row[4] ?? '')];
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped_existing' => [], 'untranslated' => 0, 'stale' => 0, 'unknown' => $unknown, 'pages' => []];
        $sources = [];
        foreach (self::sourcePages($from) as $page) {
            $sources[(int) $page['id']] = $page;
        }

        foreach ($byPage as $pageId => $cells) {
            $page = $sources[$pageId] ?? null;
            if ($page === null) {
                $result['unknown'] += count($cells);
                continue;
            }

            // Перевод по ключу — только если исходник на сайте тот же, с
            // которого переводили.
            $current = self::currentTexts($page, $from);
            $translated = [];
            foreach ($cells as $key => [$source, $translation]) {
                if (trim($translation) === '') {
                    $result['untranslated']++;
                    continue;
                }
                if (!array_key_exists($key, $current)) {
                    $result['unknown']++;
                    continue;
                }
                if (self::normalize($current[$key]) !== self::normalize($source)) {
                    $result['stale']++;
                    continue;
                }
                $translated[$key] = $translation;
            }
            if ($translated === []) {
                continue;
            }

            $outcome = self::applyPage($page, $from, $to, $translated, $overwrite);
            if ($outcome === 'skipped') {
                $result['skipped_existing'][] = (string) $page['title'];
                continue;
            }
            if ($outcome === 'created') {
                $result['created']++;
            } else {
                $result['updated']++;
            }
            $result['pages'][] = (string) $page['title'];
        }

        if ($result['created'] + $result['updated'] > 0) {
            Cache::forgetPrefix('page:');
        }

        return $result;
    }

    /**
     * Языковая версия страницы с переводом. Возвращает created | updated | skipped.
     *
     * @param array<string, mixed> $page
     * @param array<string, string> $translated
     */
    private static function applyPage(array $page, string $from, string $to, array $translated, bool $overwrite): string
    {
        $sourceId = (int) $page['id'];
        $module = ($page['entity_type'] ?? 'page') === 'project' ? 'projects' : 'pages';
        $existingId = self::translationId($sourceId, $to);
        if ($existingId !== null && !$overwrite && Block::forPage($existingId, $to) !== []) {
            return 'skipped';
        }

        $targetId = $existingId ?? TranslationGroupHelper::createTranslation($module, $sourceId, $to);
        if ($existingId !== null) {
            BlockSnippet::autoBackup($existingId, $to, (string) $page['title']);
        }

        $blocks = [];
        foreach (self::blockTree($sourceId, $from) as $block) {
            $blocks[] = self::snapshot($block, $translated);
        }
        BlockSnippet::applyToPage($blocks, $targetId, $to, true);

        $set = [];
        $params = [':id' => $targetId];
        foreach (array_keys(self::PAGE_FIELDS) as $field) {
            $key = 'page:' . $sourceId . ':' . $field;
            if (isset($translated[$key])) {
                $set[] = '`' . $field . '` = :' . $field;
                $params[':' . $field] = $field === 'title' ? mb_substr(trim($translated[$key]), 0, 255) : trim($translated[$key]);
            }
        }
        if ($set !== []) {
            Database::pdo()->prepare('UPDATE pages SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }

        return $existingId === null ? 'created' : 'updated';
    }

    private static function translationId(int $sourceId, string $lang): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.id FROM pages p
             JOIN pages s ON s.id = :sid
             WHERE p.lang = :lang AND p.deleted_at IS NULL AND p.entity_type = s.entity_type
               AND COALESCE(NULLIF(p.translation_group_id, 0), p.id) = COALESCE(NULLIF(s.translation_group_id, 0), s.id)
             LIMIT 1'
        );
        $stmt->execute([':sid' => $sourceId, ':lang' => $lang]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Блок как снимок шаблона (тот же вид, что применяет библиотека шаблонов),
     * с переводом вместо исходного текста.
     *
     * @param array<string, mixed> $block узел blockTree()
     * @param array<string, string> $translated
     * @return array<string, mixed>
     */
    private static function snapshot(array $block, array $translated): array
    {
        $data = $block['data'];
        if (!in_array($block['type'], self::SKIP_TYPES, true)) {
            $prefix = 'block:' . (int) $block['id'] . ':';
            $data = self::mapTexts($data, '', '', static fn (string $path, string $value): string
                => $translated[$prefix . $path] ?? $value);
        }
        $item = [
            'type' => $block['type'],
            'title' => $block['title'],
            'data' => $data,
            'custom_css' => $block['custom_css'],
            'is_active' => $block['is_active'],
            'column_index' => $block['column_index'],
        ];
        $children = is_array($block['children'] ?? null) ? $block['children'] : [];
        if ($children !== []) {
            $item['children'] = array_map(
                static fn (array $child): array => self::snapshot($child, $translated),
                $children
            );
        }

        return $item;
    }

    /**
     * Тексты страницы, как они лежат сейчас: ключ → значение.
     *
     * @param array<string, mixed> $page
     * @return array<string, string>
     */
    private static function currentTexts(array $page, string $lang): array
    {
        $out = [];
        foreach (array_keys(self::PAGE_FIELDS) as $field) {
            $value = trim((string) ($page[$field] ?? ''));
            if ($value !== '') {
                $out['page:' . (int) $page['id'] . ':' . $field] = $value;
            }
        }
        foreach (self::blockTree((int) $page['id'], $lang) as $block) {
            foreach (self::blockTexts($block) as [$key, , $value]) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Строки таблицы для блока и его вложенных блоков: [ключ, подпись поля, текст].
     *
     * @param array<string, mixed> $block узел blockTree()
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function blockTexts(array $block): array
    {
        $out = [];
        $blockType = (string) $block['type'];
        if (!in_array($blockType, self::SKIP_TYPES, true)) {
            $label = BlockTypeRegistry::editorLabels()[$blockType] ?? $blockType;
            $prefix = 'block:' . (int) $block['id'] . ':';
            self::mapTexts($block['data'], '', '', static function (string $path, string $value) use (&$out, $prefix, $label): string {
                $out[] = [$prefix . $path, 'Блок «' . $label . '» → ' . str_replace('/', ' → ', $path), $value];

                return $value;
            });
        }
        foreach (is_array($block['children'] ?? null) ? $block['children'] : [] as $child) {
            array_push($out, ...self::blockTexts($child));
        }

        return $out;
    }

    /**
     * Тексты блока для читателя (те же поля, что идут в перевод файлом):
     * их же индексирует поиск по сайту. У блока «HTML» текстов нет.
     *
     * @return list<string>
     */
    public static function readerTexts(string $type, mixed $data): array
    {
        if (in_array($type, self::SKIP_TYPES, true)) {
            return [];
        }
        $out = [];
        self::mapTexts($data, '', '', static function (string $path, string $value) use (&$out): string {
            $out[] = $value;

            return $value;
        });

        return $out;
    }

    /**
     * Обход данных блока: $fn получает путь и текст каждого поля для
     * читателя и возвращает новое значение.
     *
     * @param callable(string, string): string $fn
     */
    private static function mapTexts(mixed $node, string $path, string $key, callable $fn): mixed
    {
        if (is_array($node)) {
            foreach ($node as $k => $child) {
                $node[$k] = self::mapTexts(
                    $child,
                    $path === '' ? (string) $k : $path . '/' . $k,
                    // У списка ключ — номер: решает имя поля, где список лежит.
                    is_string($k) ? $k : $key,
                    $fn
                );
            }

            return $node;
        }
        if (!is_string($node) || !TextReplace::isReaderText($key, $node)) {
            return $node;
        }

        return $fn($path, $node);
    }

    /**
     * Блоки страницы деревом: верхний уровень и вложенные в контейнеры.
     *
     * @return list<array<string, mixed>>
     */
    private static function blockTree(int $pageId, string $lang): array
    {
        $node = static fn (array $row, array $children): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'title' => $row['title'] !== null ? (string) $row['title'] : null,
            'data' => is_array($d = json_decode((string) $row['data'], true)) ? $d : [],
            'custom_css' => (string) ($row['custom_css'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'column_index' => (int) ($row['column_index'] ?? 0),
            'children' => $children,
        ];

        $tree = [];
        foreach (Block::forPage($pageId, $lang) as $row) {
            $children = array_map(static fn (array $c): array => $node($c, []), Block::childrenOf((int) $row['id']));
            $tree[] = $node($row, $children);
        }

        return $tree;
    }

    /** @return list<array<string, mixed>> */
    private static function sourcePages(string $lang): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, entity_type, title, slug, `lead`, meta_title, meta_description
             FROM pages WHERE lang = :lang AND deleted_at IS NULL
             ORDER BY entity_type, id'
        );
        $stmt->execute([':lang' => $lang]);

        return Database::rows($stmt);
    }

    /** @param array<string, mixed> $page */
    private static function pageLabel(array $page): string
    {
        $kind = ($page['entity_type'] ?? 'page') === 'project' ? 'Проект' : 'Страница';

        return $kind . ' «' . trim((string) $page['title']) . '» (/' . (string) $page['slug'] . ')';
    }

    /**
     * @param list<list<string>> $rows
     * @return array{0: string, 1: string}
     */
    private static function languages(array $rows): array
    {
        foreach (array_slice($rows, 0, 5) as $row) {
            $key = trim((string) ($row[0] ?? ''));
            if (preg_match('/^' . preg_quote(self::META_PREFIX, '/') . '([a-z]{2,10})>([a-z]{2,10})$/', $key, $m) === 1) {
                return [$m[1], $m[2]];
            }
        }

        throw new InvalidArgumentException('Это не файл перевода страниц: нет служебной строки с языками. Выгрузите таблицу заново.');
    }

    /** @param array<int, ?int> $owners память «блок → страница» на время загрузки */
    private static function pageIdOf(string $key, array &$owners): ?int
    {
        if (preg_match('/^page:(\d+):[a-z_]+$/', $key, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/^block:(\d+):/', $key, $m) === 1) {
            $blockId = (int) $m[1];
            if (!array_key_exists($blockId, $owners)) {
                $stmt = Database::pdo()->prepare('SELECT page_id FROM blocks WHERE id = :id');
                $stmt->execute([':id' => $blockId]);
                $owner = $stmt->fetchColumn();
                $owners[$blockId] = $owner === false ? null : (int) $owner;
            }

            return $owners[$blockId];
        }

        return null;
    }

    /** Сравнение без разницы в переносах строк и крайних пробелах: таблица их меняет. */
    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\r\n?/', "\n", $text));
    }
}
