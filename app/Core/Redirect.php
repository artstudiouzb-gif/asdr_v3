<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Редирект из контроллера: `Redirect::to('/admin/links')` вместо пары
 * `header('Location: …'); exit;`. См. `RedirectException`.
 */
final class Redirect
{
    /** @param int $status 302 по умолчанию; 303 — «после POST смотри GET», 301 — навсегда */
    public static function to(string $url, int $status = 302): never
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            $status = 302;
        }

        throw new RedirectException($url, $status);
    }
}
