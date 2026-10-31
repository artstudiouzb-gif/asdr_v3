<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Cache;
use App\Core\Fragment;
use App\Core\Input;
use App\Core\Locale;
use App\Core\RateLimiter;
use App\Core\Search;
use App\Core\View;

/**
 * Публичный поиск по сайту (страницы, новости, записи публичных типов).
 */
final class SearchController
{
    /** Результатов на странице выдачи. */
    private const PER_PAGE = 20;

    /** Вид материала => подпись фильтра (порядок кнопок). */
    public const FILTERS = [
        'page' => 'Страницы',
        'news' => 'Новости',
        'document' => 'Документы',
        'project' => 'Проекты',
        'entry' => 'Каталоги',
        'album' => 'Фотоальбомы',
        'team' => 'Сотрудники',
    ];

    public function index(): void
    {
        $input = Input::query();
        $query = $input->str('q', '', 120);
        $type = $input->oneOf('type', array_keys(self::FILTERS), '');
        $page = $input->int('page', 1, 1, 100);
        $all = [];

        if ($query !== '' && mb_strlen($query) >= 2) {
            // Лёгкий анти-абуз: не более 30 поисков в минуту с одного IP.
            if (RateLimiter::throttle('site_search', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 30, 1)) {
                $all = Cache::remember(
                    'search:' . Locale::current() . ':' . hash('sha256', mb_strtolower($query)),
                    static fn (): array => Search::all($query),
                    60
                );
                if ($page === 1 && $type === '') {
                    \App\Models\SearchLog::record($query, count($all));
                }
            }
        }

        $counts = [];
        foreach ($all as $row) {
            $entity = (string) ($row['entity'] ?? '');
            if (isset(self::FILTERS[$entity])) {
                $counts[$entity] = ($counts[$entity] ?? 0) + 1;
            }
        }
        $filtered = $type === '' ? $all : array_values(array_filter(
            $all,
            static fn (array $row): bool => ($row['entity'] ?? '') === $type
        ));
        $pages = max(1, (int) ceil(count($filtered) / self::PER_PAGE));
        $page = min($page, $pages);

        View::render('site/search', [
            'query' => $query,
            'results' => array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => count($filtered),
            'grandTotal' => count($all),
            'counts' => $counts,
            'type' => $type,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * Подсказки живого поиска: несколько первых результатов под полем ввода.
     * Отдаётся фрагментом — тем же механизмом, что и AJAX-фильтры списков.
     */
    public function suggest(): void
    {
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
        $results = [];

        if (mb_strlen($query) >= 2
            // Подсказки летят чаще обычного поиска (по одному на паузу в
            // наборе), поэтому лимит выше, но он всё же есть.
            && RateLimiter::throttle('site_suggest', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 90, 1)) {
            $results = Cache::remember(
                'search-suggest:' . Locale::current() . ':' . hash('sha256', mb_strtolower($query)),
                static fn (): array => Search::quick($query, 6),
                30
            );
        }

        Fragment::render('site/_search_suggest', [
            'results' => $results,
            'query' => $query,
            'allUrl' => Locale::url('search', Locale::current()) . '?q=' . rawurlencode($query),
        ]);
    }
}
