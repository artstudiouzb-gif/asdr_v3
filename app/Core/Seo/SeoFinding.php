<?php

declare(strict_types=1);

namespace App\Core\Seo;

/**
 * Одна находка проверки индексации.
 *
 * Находка — это не «ошибка вообще», а конкретный ответ на вопрос «почему
 * страница может не попасть в поиск». Поэтому у неё есть примеры адресов: без
 * них редактору сообщают о проблеме, но не о том, где её чинить.
 */
final class SeoFinding
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_OK = 'ok';

    /** @param list<string> $samples */
    public function __construct(
        public readonly string $key,
        public readonly string $level,
        public readonly string $title,
        public readonly string $detail = '',
        public readonly int $count = 0,
        public readonly array $samples = [],
        public readonly string $fixUrl = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'level' => $this->level,
            'title' => $this->title,
            'detail' => $this->detail,
            'count' => $this->count,
            'samples' => $this->samples,
            'fix_url' => $this->fixUrl,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $level = (string) ($row['level'] ?? self::LEVEL_WARNING);

        return new self(
            (string) ($row['key'] ?? ''),
            in_array($level, [self::LEVEL_ERROR, self::LEVEL_WARNING, self::LEVEL_OK], true) ? $level : self::LEVEL_WARNING,
            (string) ($row['title'] ?? ''),
            (string) ($row['detail'] ?? ''),
            (int) ($row['count'] ?? 0),
            // Именно 'is_string', а не своя замыкание-обёртка: анализатор
            // сужает тип по встроенной проверке и видит list<string>, а по
            // замыканию — нет.
            array_values(array_filter((array) ($row['samples'] ?? []), 'is_string')),
            (string) ($row['fix_url'] ?? ''),
        );
    }
}
