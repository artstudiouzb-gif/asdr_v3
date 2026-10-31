<?php

declare(strict_types=1);

/*
 * Пересборка индекса поиска по сайту:
 *   php app/Console/search_index.php
 *
 * Обычно не нужна: индекс пересобирается после каждой правки контента и
 * ночью (gdpr_cleanup.php). Команда — для первой сборки после обновления и
 * на случай, если веб-сервер не дал выполнить работу после ответа.
 * Подробности — App\Core\SearchIndex.
 */

require __DIR__ . '/../Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../Core/bootstrap.php';

$started = microtime(true);
$rows = \App\Core\SearchIndex::rebuild();
printf('%s Индекс поиска: %d строк за %.2f с%s', date('c'), $rows, microtime(true) - $started, PHP_EOL);
