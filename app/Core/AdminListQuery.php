<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;

/** Нормализация общих GET-фильтров списков административной панели. */
final class AdminListQuery
{
    /**
     * @param array<string,mixed> $input
     * @param array<int,string> $allowedSorts
     * @return array{q:string,status:string,lang:string,sort:string,from:string,to:string,page:int,per_page:int,offset:int}
     */
    public static function normalize(
        array $input,
        array $allowedSorts,
        string $defaultSort,
        bool $allowDates = false
    ): array {
        $q = mb_substr(trim((string) ($input['q'] ?? '')), 0, 120);
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['', 'published', 'draft'], true)) {
            $status = '';
        }

        // Первый вход в языковой список показывает основной язык сайта.
        // Все языки остаются явным выбором `lang=all`; это позволяет отличить
        // его от URL без параметра и сохранить при пагинации/POST-возврате.
        $lang = array_key_exists('lang', $input)
            ? trim((string) $input['lang'])
            : Language::defaultCode();
        if ($lang === '') {
            $lang = 'all'; // совместимость со старыми ссылками ?lang=
        } elseif ($lang !== 'all' && !Language::isActive($lang)) {
            $lang = Language::defaultCode();
        }

        $sort = (string) ($input['sort'] ?? $defaultSort);
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = $defaultSort;
        }

        $perPage = (int) ($input['per_page'] ?? 20);
        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }
        $page = max(1, min(100000, (int) ($input['page'] ?? 1)));

        $from = $allowDates ? self::date((string) ($input['from'] ?? '')) : '';
        $to = $allowDates ? self::date((string) ($input['to'] ?? '')) : '';
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'q' => $q,
            'status' => $status,
            'lang' => $lang,
            'sort' => $sort,
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    /** Корректирует страницу после получения общего количества записей. */
    public static function fitPage(array $filters, int $total): array
    {
        $pages = max(1, (int) ceil($total / max(1, (int) $filters['per_page'])));
        $filters['page'] = min((int) $filters['page'], $pages);
        $filters['offset'] = ($filters['page'] - 1) * (int) $filters['per_page'];

        return [$filters, $pages];
    }

    /** Параметры, которые можно безопасно вернуть в URL и массовую форму. */
    public static function urlParams(array $filters): array
    {
        return array_filter([
            'q' => $filters['q'] !== '' ? $filters['q'] : null,
            'status' => $filters['status'] !== '' ? $filters['status'] : null,
            'lang' => $filters['lang'] ?? Language::defaultCode(),
            // Категория есть только у списка новостей; для остальных списков
            // ключа нет — значение отсеется как null.
            'category' => ($filters['category'] ?? '') !== '' ? (string) $filters['category'] : null,
            'sort' => $filters['sort'] ?? null,
            'from' => $filters['from'] !== '' ? $filters['from'] : null,
            'to' => $filters['to'] !== '' ? $filters['to'] : null,
            'page' => (int) $filters['page'] > 1 ? (int) $filters['page'] : null,
            'per_page' => (int) $filters['per_page'] !== 20 ? (int) $filters['per_page'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** Восстанавливает только разрешённые параметры списка после POST-действия. */
    public static function returnPath(string $path, mixed $rawQuery): string
    {
        parse_str(mb_substr((string) $rawQuery, 0, 1000), $input);
        $allowed = ['q', 'status', 'lang', 'category', 'sort', 'from', 'to', 'page', 'per_page'];
        $params = [];
        foreach ($allowed as $key) {
            if (!isset($input[$key]) || !is_scalar($input[$key])) {
                continue;
            }
            $value = mb_substr(trim((string) $input[$key]), 0, 120);
            if ($value !== '') {
                $params[$key] = $value;
            }
        }
        $query = http_build_query($params);

        return $path . ($query !== '' ? '?' . $query : '');
    }

    private static function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
