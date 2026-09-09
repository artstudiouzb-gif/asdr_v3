<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\CalendarGrid;
use App\Core\Locale;
use App\Core\View;
use App\Models\ContentEntry;
use App\Models\ContentType;

/**
 * Публичный календарь мероприятий: месячная сетка по записям типа контента
 * «Мероприятия» (slug meropriyatiya, поле event_date).
 */
final class CalendarController
{
    private const TYPE_SLUG = 'meropriyatiya';
    private const DATE_FIELD = 'event_date';

    public function index(): void
    {
        $type = ContentType::findBySlug(self::TYPE_SLUG);
        if (!$type || (int) ($type['is_public'] ?? 0) !== 1) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$year, $month] = CalendarGrid::parseMonth((string) ($_GET['m'] ?? ''));

        // Мероприятий немного — берём все опубликованные и группируем по дате.
        $entries = ContentEntry::forTypePublic((int) $type['id'], '', 'new', 500, 0, Locale::current());
        $byDate = CalendarGrid::groupByDate($entries, self::DATE_FIELD, $year, $month);

        View::render('site/calendar', [
            'type' => $type,
            'weeks' => CalendarGrid::build($year, $month),
            'byDate' => $byDate,
            'calLabel' => CalendarGrid::label($year, $month, Locale::current()),
            'weekdays' => CalendarGrid::weekdays(Locale::current()),
            'prevMonth' => CalendarGrid::shiftMonth($year, $month, -1),
            'nextMonth' => CalendarGrid::shiftMonth($year, $month, 1),
            'today' => date('Y-m-d'),
        ]);
    }
}
