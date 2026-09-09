<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Лёгкий генератор QR-кодов на чистом PHP (без внешних библиотек и API).
 * Кодирует данные в байтовом режиме (Byte mode, ISO-8859-1/UTF-8 байты),
 * уровень коррекции ошибок M, версии 1–10 (до 213 байт — с запасом хватает
 * для otpauth-URI 2FA). Возвращает готовую SVG-строку.
 *
 * Реализованы: построение битового потока, коды Рида — Соломона над GF(256),
 * разбиение на блоки и их чередование, размещение модулей, 8 масок с выбором
 * по штрафным очкам, служебная информация формата (BCH).
 */
final class QrCode
{
    private const EC_LEVEL_M = 0;

    /** Байтовая ёмкость данных для уровня M, версии 1..6. */
    private const DATA_CAPACITY_M = [1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86, 6 => 108];

    /** EC-кодовых слов на блок для уровня M, версии 1..6. */
    private const EC_PER_BLOCK_M = [1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16];

    /**
     * Разбиение на блоки для уровня M, версии 1..6.
     * Каждый элемент: [[кол-во блоков, данных-слов в блоке], ...].
     */
    private const BLOCKS_M = [
        1 => [[1, 16]],
        2 => [[1, 28]],
        3 => [[1, 44]],
        4 => [[2, 32]],
        5 => [[2, 43]],
        6 => [[4, 27]],
    ];

    /** Позиции центров выравнивающих шаблонов по версиям. */
    private const ALIGNMENT_POSITIONS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
    ];

    private static array $expTable = [];
    private static array $logTable = [];

    /**
     * Возвращает SVG-строку QR-кода для указанного текста.
     */
    public static function svg(string $data, int $moduleSize = 6, int $margin = 4): string
    {
        $matrix = self::encode($data);
        $count = count($matrix);
        $dimension = ($count + 2 * $margin) * $moduleSize;

        $rects = '';
        for ($y = 0; $y < $count; $y++) {
            for ($x = 0; $x < $count; $x++) {
                if ($matrix[$y][$x]) {
                    $px = ($x + $margin) * $moduleSize;
                    $py = ($y + $margin) * $moduleSize;
                    $rects .= sprintf('<rect x="%d" y="%d" width="%d" height="%d"/>', $px, $py, $moduleSize, $moduleSize);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" shape-rendering="crispEdges">'
            . '<rect width="%d" height="%d" fill="#ffffff"/><g fill="#000000">%s</g></svg>',
            $dimension,
            $dimension,
            $dimension,
            $dimension,
            $dimension,
            $dimension,
            $rects
        );
    }

    /**
     * @return array<int, array<int, int>> матрица 0/1
     */
    public static function encode(string $data, ?int $forceMask = null): array
    {
        $bytes = array_values(unpack('C*', $data) ?: []);
        $length = count($bytes);

        $version = self::chooseVersion($length);
        $dataCodewords = self::buildDataCodewords($bytes, $version);
        $finalCodewords = self::interleaveWithEc($dataCodewords, $version);

        $size = 17 + $version * 4;
        [$matrix, $reserved] = self::buildBaseMatrix($version, $size);
        self::placeData($matrix, $reserved, $finalCodewords, $size);

        // Выбор оптимальной маски по штрафным очкам (или форсированной — для тестов).
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = null;
        for ($mask = 0; $mask < 8; $mask++) {
            if ($forceMask !== null && $mask !== $forceMask) {
                continue;
            }
            $candidate = $matrix;
            self::applyMask($candidate, $reserved, $mask, $size);
            self::placeFormatInfo($candidate, $mask, $size);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMatrix = $candidate;
            }
        }

        return $bestMatrix ?? $matrix;
    }

    // Максимальная поддерживаемая версия. Версии 1–6 покрывают компактный
    // otpauth-URI (до ~108 байт при уровне M) и полностью проверены
    // декодером. Для больших данных вызывающий код показывает ручной ключ.
    private const MAX_VERSION = 6;

    private static function chooseVersion(int $byteLength): int
    {
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $charCountBits = 8; // версии 1–9: 8-битный счётчик в байтовом режиме
            $capacityBits = self::DATA_CAPACITY_M[$v] * 8;
            $neededBits = 4 + $charCountBits + $byteLength * 8;
            if ($neededBits <= $capacityBits) {
                return $v;
            }
        }
        throw new \RuntimeException('QR: данные слишком длинны для генератора (максимум ~108 байт).');
    }

    /**
     * Строит поток данных-кодовых слов (без EC): режим, счётчик, данные,
     * терминатор, выравнивание и заполняющие байты.
     */
    private static function buildDataCodewords(array $bytes, int $version): array
    {
        $length = count($bytes);
        $charCountBits = $version <= 9 ? 8 : 16;

        $bits = [];
        self::pushBits($bits, 0b0100, 4);            // индикатор байтового режима
        self::pushBits($bits, $length, $charCountBits);
        foreach ($bytes as $b) {
            self::pushBits($bits, $b, 8);
        }

        $capacityBits = self::DATA_CAPACITY_M[$version] * 8;

        // Терминатор (до 4 нулей).
        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }
        // Выравнивание до целого байта.
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | ($bits[$i + $j] ?? 0);
            }
            $codewords[] = $byte;
        }

        // Заполняющие байты 0xEC / 0x11.
        $pad = [0xEC, 0x11];
        $k = 0;
        $totalDataCodewords = self::DATA_CAPACITY_M[$version];
        while (count($codewords) < $totalDataCodewords) {
            $codewords[] = $pad[$k % 2];
            $k++;
        }

        return $codewords;
    }

    /**
     * Разбивает данные на блоки, считает EC для каждого и чередует их.
     */
    private static function interleaveWithEc(array $dataCodewords, int $version): array
    {
        $ecPerBlock = self::EC_PER_BLOCK_M[$version];
        $blockSpec = self::BLOCKS_M[$version];

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($blockSpec as [$count, $dataLen]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($dataCodewords, $offset, $dataLen);
                $offset += $dataLen;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        $result = [];

        // Чередование данных.
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        // Чередование EC.
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    // ---- Reed–Solomon над GF(256) ----

    private static function initGf(): void
    {
        if (!empty(self::$expTable)) {
            return;
        }
        self::$expTable = array_fill(0, 512, 0);
        self::$logTable = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D; // порождающий многочлен QR
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initGf();

        // Порождающий многочлен степени ecCount.
        $generator = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coeff) {
                $next[$j] ^= $coeff;
                $next[$j + 1] ^= self::gfMul($coeff, self::$expTable[$i]);
            }
            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $ecCount, 0));
        $dataLen = count($data);
        for ($i = 0; $i < $dataLen; $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($generator as $j => $coeff) {
                $remainder[$i + $j] ^= self::gfMul($coeff, $factor);
            }
        }

        return array_slice($remainder, $dataLen, $ecCount);
    }

    // ---- Размещение модулей ----

    /**
     * @return array{0: array, 1: array} матрица и карта зарезервированных модулей
     */
    private static function buildBaseMatrix(int $version, int $size): array
    {
        $matrix = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $placeFinder = static function (int $row, int $col) use (&$matrix, &$reserved, $size): void {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $row + $r;
                    $cc = $col + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                        continue;
                    }
                    $isBorder = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                        || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                    $isCenter = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $matrix[$rr][$cc] = ($isBorder || $isCenter) ? 1 : 0;
                    $reserved[$rr][$cc] = true;
                }
            }
        };

        $placeFinder(0, 0);
        $placeFinder(0, $size - 7);
        $placeFinder($size - 7, 0);

        // Тайминговые линии.
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if (!$reserved[6][$i]) {
                $matrix[6][$i] = $val;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $matrix[$i][6] = $val;
                $reserved[$i][6] = true;
            }
        }

        // Выравнивающие шаблоны.
        $positions = self::ALIGNMENT_POSITIONS[$version];
        foreach ($positions as $r) {
            foreach ($positions as $c) {
                if ($reserved[$r][$c]) {
                    continue; // пересечение с finder
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $rr = $r + $dr;
                        $cc = $c + $dc;
                        $ring = max(abs($dr), abs($dc));
                        $matrix[$rr][$cc] = ($ring === 1) ? 0 : 1;
                        $reserved[$rr][$cc] = true;
                    }
                }
            }
        }

        // Тёмный модуль.
        $matrix[$size - 8][8] = 1;
        $reserved[$size - 8][8] = true;

        // Резерв зон формата.
        for ($i = 0; $i < 9; $i++) {
            if (!$reserved[8][$i]) {
                $reserved[8][$i] = true;
            }
            if (!$reserved[$i][8]) {
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // Версии выше 6 не используются (см. MAX_VERSION), поэтому отдельная
        // зона информации о версии (обязательная для v>=7) не требуется.

        return [$matrix, $reserved];
    }

    private static function placeData(array &$matrix, array $reserved, array $codewords, int $size): void
    {
        $bits = [];
        foreach ($codewords as $cw) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($cw >> $i) & 1;
            }
        }

        $bitIndex = 0;
        $upward = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // пропускаем вертикальную тайминговую линию
            }
            $range = $upward ? range($size - 1, 0) : range(0, $size - 1);
            foreach ($range as $row) {
                for ($c = 0; $c < 2; $c++) {
                    $cc = $col - $c;
                    if ($reserved[$row][$cc]) {
                        continue;
                    }
                    $matrix[$row][$cc] = $bits[$bitIndex] ?? 0;
                    $bitIndex++;
                }
            }
            $upward = !$upward;
        }
    }

    private static function applyMask(array &$matrix, array $reserved, int $mask, int $size): void
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
                    6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                    7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                    default => false,
                };
                if ($flip) {
                    $matrix[$r][$c] ^= 1;
                }
            }
        }
    }

    private static function placeFormatInfo(array &$matrix, int $mask, int $size): void
    {
        $raw = self::formatInfoBits(self::EC_LEVEL_M, $mask);

        // Format info размещается старшим битом вперёд: ячейка k получает
        // бит (14 - k). Разворачиваем 15-битное значение, чтобы дальнейший
        // код, кладущий «бит i в позицию i», давал корректную раскладку.
        $format = 0;
        for ($i = 0; $i < 15; $i++) {
            $format |= (($raw >> $i) & 1) << (14 - $i);
        }

        // Первая копия — вокруг верхнего левого finder.
        for ($i = 0; $i <= 5; $i++) {
            $matrix[8][$i] = ($format >> $i) & 1;
        }
        $matrix[8][7] = ($format >> 6) & 1;
        $matrix[8][8] = ($format >> 7) & 1;
        $matrix[7][8] = ($format >> 8) & 1;
        for ($i = 9; $i <= 14; $i++) {
            $matrix[14 - $i][8] = ($format >> $i) & 1;
        }

        // Вторая копия.
        for ($i = 0; $i <= 7; $i++) {
            $matrix[$size - 1 - $i][8] = ($format >> $i) & 1;
        }
        for ($i = 8; $i <= 14; $i++) {
            $matrix[8][$size - 15 + $i] = ($format >> $i) & 1;
        }
    }

    private static function formatInfoBits(int $ecLevel, int $mask): int
    {
        // Уровень M кодируется как 00; далее 3 бита маски.
        $data = ((0b00) << 3) | $mask;
        $bch = $data << 10;
        $g = 0b10100110111;
        for ($i = 14; $i >= 10; $i--) {
            if (($bch >> $i) & 1) {
                $bch ^= $g << ($i - 10);
            }
        }
        $format = (($data << 10) | $bch) ^ 0b101010000010010;

        return $format & 0x7FFF;
    }

    // ---- Штрафные очки для выбора маски ----

    private static function penalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // Правило 1: серии одного цвета >= 5 подряд (по строкам и столбцам).
        for ($r = 0; $r < $size; $r++) {
            $penalty += self::lineRunPenalty($matrix[$r], $size);
        }
        for ($c = 0; $c < $size; $c++) {
            $col = [];
            for ($r = 0; $r < $size; $r++) {
                $col[] = $matrix[$r][$c];
            }
            $penalty += self::lineRunPenalty($col, $size);
        }

        // Правило 2: блоки 2x2 одного цвета.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $matrix[$r][$c];
                if ($v === $matrix[$r][$c + 1] && $v === $matrix[$r + 1][$c] && $v === $matrix[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Правило 3: паттерн 1:1:3:1:1 (finder-подобный).
        $pattern1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $pattern2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $slice = array_slice($matrix[$r], $c, 11);
                if ($slice === $pattern1 || $slice === $pattern2) {
                    $penalty += 40;
                }
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                $slice = [];
                for ($k = 0; $k < 11; $k++) {
                    $slice[] = $matrix[$r + $k][$c];
                }
                if ($slice === $pattern1 || $slice === $pattern2) {
                    $penalty += 40;
                }
            }
        }

        // Правило 4: баланс тёмных модулей.
        $dark = 0;
        foreach ($matrix as $row) {
            $dark += array_sum($row);
        }
        $total = $size * $size;
        $ratio = ($dark * 100) / $total;
        $prev = (int) (floor($ratio / 5) * 5);
        $next = $prev + 5;
        $penalty += min(abs($prev - 50), abs($next - 50)) / 5 * 10;

        return (int) $penalty;
    }

    private static function lineRunPenalty(array $line, int $size): int
    {
        $penalty = 0;
        $runColor = $line[0];
        $runLength = 1;
        for ($i = 1; $i < $size; $i++) {
            if ($line[$i] === $runColor) {
                $runLength++;
            } else {
                if ($runLength >= 5) {
                    $penalty += 3 + ($runLength - 5);
                }
                $runColor = $line[$i];
                $runLength = 1;
            }
        }
        if ($runLength >= 5) {
            $penalty += 3 + ($runLength - 5);
        }

        return $penalty;
    }

    private static function pushBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }
}
