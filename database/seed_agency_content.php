<?php

declare(strict_types=1);

/*
 * Загрузка реального контента Агентства вместо демо-содержимого.
 *
 *   php database/seed_agency_content.php --dry-run   # показать, что изменится
 *   php database/seed_agency_content.php             # применить
 *
 * Вся работа — в App\Core\AgencyContentSeeder, здесь только консольная обёртка,
 * чтобы ту же логику могли гонять тесты на настоящей базе.
 *
 * Что делает:
 *   - создаёт или перезаписывает страницы из database/content/agency_content.php
 *     («Об Агентстве», «Руководство», профили руководителей) вместе с их
 *     блоками — для каждого языка свой стек блоков, версии связаны
 *     translation_group_id;
 *   - создаёт или обновляет записи руководителей в разделе «Команда»
 *     с переводами;
 *   - сбрасывает кэш страниц.
 *
 * Идемпотентно: повторный запуск приводит содержимое к состоянию фикстуры.
 * Блоки страницы при этом ПЕРЕЗАПИСЫВАЮТСЯ — ручные правки этих страниц в
 * админке будут потеряны, поэтому после первой выкладки контент правят в
 * админке, а не повторным запуском.
 *
 * Меню скрипт не трогает: структура меню — решение владельца. Демо-меню уже
 * ссылается на slug o-nas / rukovodstvo / direktor, поэтому ссылки не ломаются;
 * пункт «Первый заместитель директора» при необходимости добавляется вручную.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\AgencyContentSeeder;
use App\Core\Database;

$dryRun = in_array('--dry-run', $argv, true);

if (!Database::isConnected()) {
    fwrite(STDERR, "Нет соединения с базой данных. Проверьте config/config.php.\n");
    exit(1);
}
$pdo = Database::pdo();
$fixture = AgencyContentSeeder::fixture();

foreach (AgencyContentSeeder::inactiveLangs($fixture, $pdo) as $lang) {
    fwrite(STDOUT, "ВНИМАНИЕ: язык «{$lang}» выключен в разделе «Языки» — страницы на нём не будут показаны на сайте.\n");
}

if ($dryRun) {
    fwrite(STDOUT, "Режим --dry-run: изменения не сохраняются.\n");
}
fwrite(STDOUT, PHP_EOL);

try {
    $report = AgencyContentSeeder::run($pdo, $dryRun, $fixture);
} catch (Throwable $e) {
    fwrite(STDERR, 'ОШИБКА: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($report['log'] as $line) {
    fwrite(STDOUT, $line . PHP_EOL);
}

fwrite(STDOUT, sprintf(
    "\nСтраницы: создано %d, обновлено %d. Блоков записано: %d. Сотрудники: создано %d, обновлено %d.%s",
    $report['pages_created'],
    $report['pages_updated'],
    $report['blocks'],
    $report['team_created'],
    $report['team_updated'],
    PHP_EOL
));

if (!$dryRun) {
    fwrite(STDOUT, "Кэш страниц сброшен. Проверьте /o-nas, /rukovodstvo, /direktor и /pervyy-zamestitel-direktora.\n");
    fwrite(STDOUT, "Пункт меню «Первый заместитель директора» при необходимости добавьте вручную в разделе «Меню».\n");
}

exit(0);
