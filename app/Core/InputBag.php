<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Присланные данные с типами: строка, число в границах, значение из закрытого
 * набора, флажок, список строк.
 *
 * Контроллеры читали `$_POST` напрямую — 681 обращение, и приведение каждый
 * раз писалось по месту: `trim((string) ($_POST['x'] ?? ''))`, `(int) (...)`,
 * `in_array(..., true) ? ... : ...`. Разница между копиями была тихой: в одной
 * строке обрезали пробелы, в соседней нет; массив, присланный вместо строки,
 * где-то давал «Array», где-то TypeError. Здесь правило одно: чужой тип значит
 * «значения нет», и вызывающий получает своё умолчание.
 *
 * Мешок строится из любого массива, поэтому контроллер, читающий `InputBag`,
 * проверяется без суперглобалов (`new InputBag([...])`).
 */
final class InputBag
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Строка без пробелов по краям. Не строка (массив, отсутствие) — умолчание.
     * Предел длины считается в символах, а не в байтах: иначе кириллица
     * резалась бы вдвое раньше латиницы и посреди буквы.
     */
    public function str(string $key, string $default = '', ?int $maxLength = null): string
    {
        $value = $this->data[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return $default;
        }
        $value = trim($value);

        return $maxLength !== null ? mb_substr($value, 0, max(0, $maxLength)) : $value;
    }

    /**
     * Целое в границах. Не число вовсе («abc», массив) — умолчание, а не ноль:
     * ноль бывает законным значением, и путать его с мусором нельзя. Выход за
     * границы прижимается к ближайшей — это число, просто лишнее.
     */
    public function int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $raw = $this->data[$key] ?? null;
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\s*[+-]?\d+\s*$/', $raw) === 1) {
            $value = (int) $raw;
        } else {
            return $default;
        }
        if ($min !== null) {
            $value = max($min, $value);
        }
        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }

    /**
     * Значение из закрытого набора. Чужое — умолчание, а не ближайшее: в списке
     * его нет, значит форма подделана (то же правило, что у `Field::intChoice`).
     *
     * @param list<string> $allowed
     */
    public function oneOf(string $key, array $allowed, string $default): string
    {
        $value = $this->str($key);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Флажок формы: неотмеченный чекбокс не присылается вовсе, поэтому
     * отсутствие — «нет». «0», «false», «off» и пустая строка — тоже «нет».
     */
    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (!is_string($value)) {
            return false;
        }

        return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
    }

    /**
     * Список строк (`name[]`). Вложенные массивы и не-строки отбрасываются,
     * строка вместо списка становится списком из одного элемента.
     *
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item)) {
                $out[] = trim((string) $item);
            }
        }

        return $out;
    }

    /**
     * Список положительных id (массовые действия). Мусор и повторы выпадают.
     *
     * @return list<int>
     */
    public function ids(string $key): array
    {
        $ids = [];
        foreach ($this->strings($key) as $item) {
            if (preg_match('/^\d+$/', $item) === 1 && (int) $item > 0) {
                $ids[(int) $item] = true;
            }
        }

        return array_keys($ids);
    }
}
