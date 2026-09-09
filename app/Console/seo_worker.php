<?php

declare(strict_types=1);

/*
 * Проверка индексации сайта.
 *   php app/Console/seo_worker.php            # проверить и записать снимок
 *   php app/Console/seo_worker.php --no-http  # только проверки по базе
 *
 * Раз в сутки (пример cron):
 *   25 4 * * * php /path/to/app/Console/seo_worker.php >> storage/logs/seo.log 2>&1
 *
 * Зачем: Google и Яндекс показывают следствие («страница не в индексе») с
 * задержкой в сутки-трое, а причина — noindex на живом адресе, редирект поверх
 * страницы, оборванная карта сайта — видна сразу и без единого ключа доступа.
 * Снимок кладётся в историю, чтобы на вопрос «это новое?» был ответ.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

use App\Core\Seo\SeoAudit;
use App\Core\Seo\SeoFinding;
use App\Models\SeoAudit as SeoAuditLog;

$withHttp = !in_array('--no-http', $argv, true);

$findings = SeoAudit::run($withHttp);
$summary = SeoAudit::summary($findings);
SeoAuditLog::save($findings);

echo 'Проверка индексации: ошибок ' . $summary['errors']
    . ', предупреждений ' . $summary['warnings']
    . ', в порядке ' . $summary['ok'] . PHP_EOL;

foreach ($findings as $finding) {
    if ($finding->level === SeoFinding::LEVEL_OK) {
        continue;
    }
    echo '  [' . ($finding->level === SeoFinding::LEVEL_ERROR ? 'ошибка' : 'внимание') . '] '
        . $finding->title
        . ($finding->count > 0 ? ' (' . $finding->count . ')' : '') . PHP_EOL;
    foreach ($finding->samples as $sample) {
        echo '      ' . $sample . PHP_EOL;
    }
}

// Ненулевой код возврата, если есть ошибки: так cron и внешний монитор узнают
// о проблеме, не разбирая текст.
exit($summary['errors'] > 0 ? 1 : 0);
