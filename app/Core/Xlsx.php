<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use ZipArchive;

/**
 * Простейшая таблица XLSX: один лист строк текста — записать и прочитать.
 *
 * Нужна для перевода страниц файлом. CSV для этого не годится, хотя и
 * проще: Excel открывает его в кодировке системы (узбекская латиница с
 * «ʻ» теряется в Windows-1251), разделитель зависит от региональных
 * настроек, а ячейку, начинающуюся с «-» или «=», читает формулой — пункт
 * списка превращается в «#ИМЯ?». XLSX — это ZIP с XML, и ZipArchive на
 * хостинге уже есть (им пакуются резервные копии), поэтому зависимость
 * не нужна.
 *
 * Чтение принимает файл, пересохранённый Excel, LibreOffice или Google
 * Таблицами: те пишут строки в общий словарь (`sharedStrings.xml`), а не в
 * ячейку, как наша запись.
 */
final class Xlsx
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const RELS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Предел распакованного листа: XML разбирается целиком в памяти. */
    public const MAX_XML_BYTES = 40 * 1024 * 1024;

    /** Excel не хранит в ячейке больше этого числа символов. */
    public const MAX_CELL_CHARS = 32767;

    /**
     * Файл XLSX из строк. Первая строка — заголовок: она закреплена и
     * выделена жирным.
     *
     * @param list<list<string>> $rows
     * @param list<int> $widths ширина столбцов в символах
     */
    public static function write(array $rows, array $widths = []): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('Не удалось создать временный файл.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось собрать файл XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Перевод" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Стиль 1 — перенос по словам и выравнивание по верху (длинный текст
        // читается целиком), стиль 2 — то же, жирным, для заголовка.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($widths !== []) {
            $sheet .= '<cols>';
            foreach ($widths as $i => $width) {
                $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(4, $width) . '" customWidth="1"/>';
            }
            $sheet .= '</cols>';
        }
        $sheet .= '<sheetData>';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $value) {
                $sheet .= '<c r="' . self::column($c) . ($r + 1) . '" t="inlineStr" s="' . ($r === 0 ? 2 : 1) . '"><is><t xml:space="preserve">'
                    . self::xml($value) . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $body = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $body;
    }

    /**
     * Строки первого листа. Пустые ячейки внутри строки сохраняют позицию.
     *
     * @return list<list<string>>
     * @throws InvalidArgumentException с текстом для редактора
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('Файл не открылся как таблица XLSX. Сохраните его в Excel как «Книга Excel (.xlsx)».');
        }
        try {
            $sheetPath = self::firstSheetPath($zip);
            $shared = [];
            $sharedXml = self::entry($zip, 'xl/sharedStrings.xml', false);
            if ($sharedXml !== '') {
                foreach (self::kids(self::parseXml($sharedXml), 'si') as $si) {
                    $shared[] = self::richText($si);
                }
            }

            $doc = self::parseXml(self::entry($zip, $sheetPath, true));
            $rows = [];
            foreach (self::kids(self::kids($doc, 'sheetData')[0] ?? null, 'row') as $row) {
                $cells = [];
                foreach (self::kids($row, 'c') as $cell) {
                    $ref = self::attr($cell, 'r');
                    $index = $ref !== '' ? self::columnIndex($ref) : count($cells);
                    $v = (string) (self::kids($cell, 'v')[0] ?? '');
                    $value = match (self::attr($cell, 't')) {
                        's' => $shared[(int) $v] ?? '',
                        'inlineStr' => self::richText(self::kids($cell, 'is')[0] ?? null),
                        default => $v,
                    };
                    while (count($cells) < $index) {
                        $cells[] = '';
                    }
                    $cells[$index] = $value;
                }
                $rows[] = array_values($cells);
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::entry($zip, 'xl/workbook.xml', true);
        $rels = self::entry($zip, 'xl/_rels/workbook.xml.rels', false);
        $sheet = self::kids(self::kids(self::parseXml($workbook), 'sheets')[0] ?? null, 'sheet')[0] ?? null;
        if ($sheet === null) {
            throw new InvalidArgumentException('В книге нет ни одного листа.');
        }
        $rid = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        if ($rid !== '' && $rels !== '') {
            foreach (self::parseXml($rels)->children(self::RELS_NS) as $rel) {
                if (self::attr($rel, 'Id') === $rid) {
                    $target = ltrim(self::attr($rel, 'Target'), '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function entry(ZipArchive $zip, string $name, bool $required): string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            if ($required) {
                throw new InvalidArgumentException('Файл не похож на таблицу XLSX: внутри нет «' . $name . '».');
            }

            return '';
        }
        // Размер распакованного содержимого проверяется до чтения: ZIP
        // в пару килобайт разворачивается в гигабайты.
        if ((int) $stat['size'] > self::MAX_XML_BYTES) {
            throw new InvalidArgumentException('Таблица слишком велика.');
        }

        return (string) $zip->getFromName($name);
    }

    private static function parseXml(string $xml): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            throw new InvalidArgumentException('Таблица повреждена: XML не разобрался.');
        }

        return $doc;
    }

    /**
     * Атрибут без пространства имён. У элемента, полученного через
     * `children($ns)`, запись `$el['t']` ищет атрибут в том же пространстве
     * и обычный `t="s"` не видит — тип ячейки терялся, и вместо текста
     * читался номер строки общего словаря.
     */
    private static function attr(\SimpleXMLElement $el, string $name): string
    {
        return (string) ($el->attributes()[$name] ?? '');
    }

    /** Текст ячейки: простой `<t>` или куски с оформлением `<r><t>`. */
    private static function richText(?\SimpleXMLElement $node): string
    {
        $t = self::kids($node, 't');
        if ($t !== []) {
            return (string) $t[0];
        }
        $text = '';
        foreach (self::kids($node, 'r') as $run) {
            $text .= (string) (self::kids($run, 't')[0] ?? '');
        }

        return $text;
    }

    /**
     * Дочерние элементы по имени — в пространстве имён таблицы, записаны они
     * с префиксом (`<x:c>`) или без.
     *
     * Обращение `$node->c` видит только элементы без префикса, а на
     * отсутствующем узле отдаёт пустышку, у которой следующий шаг — `null`:
     * так файл из чужой программы ронял загрузку на `foreach (null)` вместо
     * того, чтобы прочитаться. Ответ здесь всегда список, пусть и пустой.
     *
     * @return list<\SimpleXMLElement>
     */
    private static function kids(?\SimpleXMLElement $node, string $name): array
    {
        if ($node === null) {
            return [];
        }
        $out = [];
        foreach ([self::MAIN_NS, ''] as $ns) {
            foreach ($node->children($ns) as $child) {
                if ($child->getName() === $name) {
                    $out[] = $child;
                }
            }
            if ($out !== []) {
                break;
            }
        }

        return $out;
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    private static function columnIndex(string $ref): int
    {
        $letters = (string) preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return max(0, $index - 1);
    }

    /** Текст для XML: экранирование и без управляющих символов, которых XML не допускает. */
    private static function xml(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
