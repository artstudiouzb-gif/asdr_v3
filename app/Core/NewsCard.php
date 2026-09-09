<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Обложка-карточка новости: поля, которые есть только у макета «Карточка».
 *
 * Это оформление обложки, а не содержание новости. Поэтому здесь своя короткая
 * строка заголовка (полный уходит в ленту, RSS и поиск, и делать его коротким
 * ради картинки нельзя), вторая плашка, показатели, подпись и сноска.
 *
 * Все поля переводимые: карточка на узбекской версии не должна остаться с
 * русскими словами. Одно место сборки — чтобы основной язык и перевод
 * нормализовались одинаково, а не расходились по двум веткам контроллера.
 */
final class NewsCard
{
    /** Больше трёх показателей в строку не встаёт даже на десктопе. */
    public const MAX_STATS = 3;

    /** @var list<string> колонки, общие у news и news_translations */
    public const FIELDS = ['card_title', 'card_badge', 'card_stats', 'card_signature', 'card_note'];

    /**
     * Поля обложки из одного среза формы (основной язык или перевод).
     *
     * @param array<string, mixed> $src
     * @return array<string, string>
     */
    public static function fromInput(array $src): array
    {
        return [
            'card_title' => self::text($src['card_title'] ?? '', 200),
            'card_badge' => self::text($src['card_badge'] ?? '', 100),
            'card_stats' => self::encodeStats($src['card_stats'] ?? []),
            'card_signature' => self::text($src['card_signature'] ?? '', 80),
            'card_note' => self::text($src['card_note'] ?? '', 120),
        ];
    }

    /**
     * Показатели для вывода: пары «значение + подпись».
     *
     * @return list<array{value: string, label: string}>
     */
    public static function stats(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = $stored !== '' ? json_decode($stored, true) : [];
        }
        if (!is_array($stored)) {
            return [];
        }

        $stats = [];
        foreach ($stored as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = self::text($item['value'] ?? '', 24);
            $label = self::text($item['label'] ?? '', 60);
            if ($value === '' && $label === '') {
                continue;
            }
            $stats[] = ['value' => $value, 'label' => $label];
            if (count($stats) >= self::MAX_STATS) {
                break;
            }
        }

        return $stats;
    }

    /** Пустая обложка не должна рисовать пустую панель поверх фотографии. */
    public static function isEmpty(array $row): bool
    {
        foreach (self::FIELDS as $field) {
            if ($field === 'card_stats') {
                if (self::stats($row[$field] ?? '') !== []) {
                    return false;
                }
                continue;
            }
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Показатели в JSON для колонки; пусто — пустая строка, а не «[]». */
    private static function encodeStats(mixed $input): string
    {
        $stats = self::stats($input);

        // Показатели набирает редактор: битый байт в подписи не должен
        // стирать всю тройку молча.
        return $stats === []
            ? ''
            : (string) json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        // Перенос строки в однострочном поле — это вставка из документа;
        // он бы сломал раскладку карточки, поэтому схлопывается в пробел.
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return mb_substr($text, 0, $limit);
    }
}
