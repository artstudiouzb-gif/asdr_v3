<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Heartbeat;
use App\Core\LinkChecker;
use App\Core\Redirect;
use App\Core\View;

/**
 * «Проверка ссылок»: битые ссылки в контенте и места, где их чинить.
 *
 * Раздел открыт редактору, а не только супер-админу: чинить ссылки в тексте —
 * его работа. Кнопка проверяет только внутренние ссылки (свой сайт, диск,
 * медиатека) — внешние ходят к чужим серверам с таймаутами, и это делает
 * воркер по cron, где ждать позволено.
 */
final class LinkController
{
    /** Сколько секунд ручная проверка может занять: дальше — следующим нажатием. */
    private const MANUAL_BUDGET = 20;

    public function index(): void
    {
        Auth::requireLogin();

        View::render('admin/links/index', [
            'problems' => LinkChecker::problems(),
            'counts' => LinkChecker::counts(),
            'lastChecked' => LinkChecker::lastCheckedAt(),
            'workerLastRun' => Heartbeat::lastRun('links'),
        ]);
    }

    public function run(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $result = LinkChecker::run(false, self::MANUAL_BUDGET);
        $message = 'Проверено внутренних ссылок: ' . $result['checked'] . '. Битых: ' . $result['broken'] . '.';
        if ($result['remaining'] > 0) {
            $message .= ' Не успели проверить ' . $result['remaining'] . ' — нажмите ещё раз.';
        }
        $result['broken'] > 0 ? Flash::error($message) : Flash::success($message);

        Redirect::to('/admin/links');
    }
}
