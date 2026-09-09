<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Search;

/**
 * AJAX-эндпоинт быстрого поиска по админке (задача 92, Ctrl+K).
 */
final class SearchController
{
    public function query(): void
    {
        Auth::requireLogin();

        header('Content-Type: application/json; charset=UTF-8');
        header('X-Robots-Tag: noindex');

        $term = (string) ($_GET['q'] ?? '');
        echo json_encode(
            ['results' => Search::query($term)],
            JSON_UNESCAPED_UNICODE
        );
    }
}
