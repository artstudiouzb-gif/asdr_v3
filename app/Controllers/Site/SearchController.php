<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Cache;
use App\Core\Fragment;
use App\Core\Locale;
use App\Core\RateLimiter;
use App\Core\Search;
use App\Core\View;

/**
 * Публичный поиск по сайту (страницы, новости, записи публичных типов).
 */
final class SearchController
{
    public function index(): void
    {
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
        $results = [];

        if ($query !== '' && mb_strlen($query) >= 2) {
            // Лёгкий анти-абуз: не более 30 поисков в минуту с одного IP.
            if (RateLimiter::throttle('site_search', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 30, 1)) {
                $results = Cache::remember(
                    'search:' . Locale::current() . ':' . hash('sha256', mb_strtolower($query)),
                    static fn (): array => Search::site($query),
                    60
                );
                \App\Models\SearchLog::record($query, count($results));
            }
        }

        View::render('site/search', [
            'query' => $query,
            'results' => $results,
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
