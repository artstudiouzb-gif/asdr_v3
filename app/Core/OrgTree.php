<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Построчный текст оргструктуры → дерево произвольной глубины.
 *
 * Раньше вложенность знала ровно один уровень: пункт с дефисом уходил в группу
 * предыдущего, а всё, что глубже, писать было некуда. Структура же меняется:
 * у отдела появляется сектор, у сектора — группа. Уровень теперь задаётся
 * отступом, как в обычном списке:
 *
 *     Департамент стратегии | /page#team-strategy
 *       Отдел планирования
 *         Сектор прогнозов
 *     * Проектный офис
 *
 * Правила:
 *   `|` — адрес ссылки (проверяется UrlGuard);
 *   `*` — акцентный пункт (проектный офис и т.п.);
 *   `-` в начале строки — плюс один уровень к предыдущей строке. Так писали
 *        вложенность до отступов, и сохранённые тексты обязаны читаться
 *        по-прежнему; в новых лучше отступ — он видно на глаз.
 *
 * Ширина одного уровня не задана жёстко: берётся наименьший отступ в блоке,
 * поэтому и «два пробела», и «четыре», и табы дают одинаковый результат.
 */
final class OrgTree
{
    /** Глубже этого не рисуем: схема перестаёт читаться раньше. */
    public const MAX_DEPTH = 4;

    /**
     * @return list<array{label: string, url: string, accent: bool, children: list<array<string, mixed>>}>
     */
    public static function parse(string $raw): array
    {
        $rows = self::rows($raw);
        if ($rows === []) {
            return [];
        }

        $unit = self::indentUnit($rows);

        $tree = [];
        // Путь до текущего узла индексами: [i] — позиция в $tree, [i][j] — в
        // его детях и так далее. Ссылок не держим: они переживают итерацию и
        // тихо переписывают соседние ветки.
        $path = [];
        $prevLevel = -1;

        foreach ($rows as $row) {
            $level = $unit > 0 ? intdiv($row['indent'], $unit) : 0;
            $level += $row['bump'];
            // Прыжок через уровень — это опечатка в отступе, а не намерение:
            // приклеиваем пункт к ближайшему возможному родителю.
            $level = max(0, min($level, $prevLevel + 1, self::MAX_DEPTH - 1));

            $node = [
                'label' => $row['label'],
                'url' => $row['url'],
                'accent' => $row['accent'],
                'children' => [],
            ];

            $bucket = &$tree;
            for ($i = 0; $i < $level; $i++) {
                $bucket = &$bucket[$path[$i]]['children'];
            }
            $bucket[] = $node;
            $path = array_slice($path, 0, $level);
            $path[$level] = count($bucket) - 1;
            unset($bucket);

            $prevLevel = $level;
        }

        return $tree;
    }

    /**
     * Разбор строк без сборки дерева: отступ, маркеры, ссылка.
     *
     * @return list<array{indent: int, bump: int, label: string, url: string, accent: bool}>
     */
    private static function rows(string $raw): array
    {
        $rows = [];
        // Строки режем явным набором переводов строки, а не `\R`: без модификатора
        // `u` он матчит байт 0x85, а это второй байт кириллической «х» —
        // «Бухгалтерия» разваливалась на «Бу» и «галтерия».
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            // Таб считаем за четыре пробела: иначе смешанный отступ давал
            // «уровень 0» строке, которая на глаз вложена.
            $line = str_replace("\t", '    ', $line);
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $text = trim($line);

            $bump = 0;
            if (preg_match('/^[-–—•]\s*(.+)$/u', $text, $m) === 1) {
                $bump = 1;
                $text = trim($m[1]);
            }

            $accent = false;
            if ($bump === 0 && preg_match('/^\*\s*(.+)$/u', $text, $m) === 1) {
                $accent = true;
                $text = trim($m[1]);
            }

            $url = '';
            if (str_contains($text, '|')) {
                [$text, $url] = array_map('trim', explode('|', $text, 2));
            }
            if ($text === '') {
                continue;
            }
            if ($url !== '' && !UrlGuard::isSafeLink($url)) {
                $url = '';
            }

            $rows[] = [
                'indent' => $indent,
                'bump' => $bump,
                'label' => $text,
                'url' => $url,
                'accent' => $accent,
            ];
        }

        return $rows;
    }

    /**
     * Ширина одного уровня — наименьший ненулевой отступ в блоке.
     *
     * @param list<array{indent: int, bump: int, label: string, url: string, accent: bool}> $rows
     */
    private static function indentUnit(array $rows): int
    {
        $unit = 0;
        foreach ($rows as $row) {
            if ($row['indent'] > 0 && ($unit === 0 || $row['indent'] < $unit)) {
                $unit = $row['indent'];
            }
        }

        return $unit;
    }
}
