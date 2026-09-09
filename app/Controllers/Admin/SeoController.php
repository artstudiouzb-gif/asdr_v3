<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Seo\SeoAudit;
use App\Core\View;
use App\Models\SeoAudit as SeoAuditLog;

/**
 * Раздел «Поиск и индексация»: почему страница может не попасть в поиск.
 *
 * Проверка запускается по cron (app/Console/seo_worker.php) и кнопкой здесь.
 * Кнопка нужна не для удобства: после правки robots или карты сайта редактору
 * важно увидеть результат сразу, а не завтра.
 */
final class SeoController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        View::render('admin/seo/index', [
            'latest' => SeoAuditLog::latest(),
            'history' => SeoAuditLog::history(20),
        ]);
    }

    public function run(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $findings = SeoAudit::run();
        $summary = SeoAudit::summary($findings);
        SeoAuditLog::save($findings);

        if ($summary['errors'] > 0) {
            Flash::error('Проверка завершена: ошибок — ' . $summary['errors']
                . ', предупреждений — ' . $summary['warnings'] . '.');
        } else {
            Flash::success('Проверка завершена: ошибок нет, предупреждений — ' . $summary['warnings'] . '.');
        }

        header('Location: /admin/seo');
        exit;
    }
}
