<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Последовательность обновления: от загрузки архива до снятия режима
 * обслуживания. Одна на всех, и это главное в этом классе.
 *
 * Обновление запускается из двух мест — кнопкой в панели (через
 * `app/Console/update_worker.php`) и руками (`scripts/update.php`, когда сайт
 * лежит и панель недоступна). Если бы каждое место несло свою копию шагов,
 * первая же правка досталась бы одной ветке и не досталась другой: проверка
 * суммы появилась бы в одной, снимок для отката — в другой. Поэтому опасная
 * часть живёт здесь, а вызывающий отвечает только за то, как показать ход.
 *
 * Порядок жёсткий и обрывается на первой неудаче. Сначала то, что можно
 * отменить без следа (загрузка, сверка суммы, проверка состава, план), потом
 * полная резервная копия, и только затем замена файлов. Сорвалась замена —
 * файлы возвращаются из снимка, снятого прямо перед ней.
 *
 * Режим обслуживания включается **перед заменой**, а не в начале: закрывать
 * сайт на время скачивания архива незачем, ломается он не от этого. Снимается
 * он в `finally` — то есть и после отказа тоже, и возвращается в то состояние,
 * в каком его застали (см. `UpdateState`).
 *
 * Ход пишется в `UpdateState` самим бегунком, а не вызывающим: панель обязана
 * видеть шаги независимо от того, кто запустил обновление, и по этим же
 * отметкам времени сорвавшееся обновление отличается от идущего.
 */
final class UpdateRunner
{
    /**
     * Выполняет обновление до релиза, описанного в `Updater::check()`.
     *
     * @param array<string,mixed> $check результат Updater::check()
     * @param callable(string):void|null $echo куда дублировать шаги (stdout у CLI)
     * @return array{release:string, copied:int, deleted:int, migrations:int, backup:string}
     * @throws \RuntimeException
     */
    public static function run(array $check, ?callable $echo = null): array
    {
        $root = Updater::root();
        $release = (string) ($check['latest'] ?? '');
        $asset = self::asset($check);

        $report = static function (string $text) use ($echo): void {
            UpdateState::step($text);
            if ($echo !== null) {
                $echo($text);
            }
        };

        $work = self::workDir($root);
        ['tree' => $newTree, 'plan' => $plan] = self::prepare($release, $asset, $work, $root, $report);

        // --- Резервная копия ----------------------------------------------
        $backup = Backup::create(true);
        $report('Резервная копия снята: ' . basename($backup));

        // --- Снимок заменяемых файлов для отката ---------------------------
        $rollbackDir = $work . '/rollback-' . gmdate('Ymd-His');
        if (!@mkdir($rollbackDir, 0750, true)) {
            throw new \RuntimeException('не удалось создать каталог отката ' . $rollbackDir);
        }
        foreach (array_merge($plan['copy'], $plan['delete']) as $relative) {
            $source = $root . '/' . $relative;
            if (!is_file($source)) {
                continue;
            }
            $target = $rollbackDir . '/' . $relative;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0750, true);
            }
            if (!@copy($source, $target)) {
                throw new \RuntimeException('не удалось сохранить для отката: ' . $relative);
            }
        }
        $report('Снимок для отката: ' . basename($rollbackDir));

        // Классы, которые понадобятся ПОСЛЕ замены, загружаем до неё: свой
        // код процесс меняет прямо под собой, и автозагрузчик подтянул бы к
        // старому окружению уже новый файл.
        class_exists(MigrationRunner::class);
        class_exists(Integrity::class);
        class_exists(Logger::class);

        // --- Замена файлов -------------------------------------------------
        $applied = ['copied' => 0, 'deleted' => 0];
        $migrated = 0;
        UpdateState::takeMaintenance();
        try {
            // С этой отметки обрыв означает возможную половину старых файлов и
            // половину новых: такой сайт сам не откроется — открытый, он отдаёт
            // 500 всем подряд, а закрытый честные 503 (см. UpdateState).
            UpdateState::markFilesTouched();
            $applied = Updater::apply($newTree, $plan, $root);
            $report('Файлы заменены: ' . $applied['copied'] . ', удалено ' . $applied['deleted']);

            $migrated = self::migrate($report);
            $report($migrated > 0 ? ('Миграций применено: ' . $migrated) : 'Новых миграций нет');

            Integrity::writeBaseline();
            $report('Эталон целостности пересобран');

            file_put_contents(
                $root . '/storage/release.json',
                json_encode(['release' => $release, 'deployed_at' => gmdate('c')], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL,
                LOCK_EX
            );
            self::clearPageCache($root);
            $report('Кэш страниц сброшен');
        } catch (\Throwable $e) {
            $report('Сбой замены, возвращаю файлы из снимка');
            foreach (Updater::listFiles($rollbackDir) as $relative) {
                $target = $root . '/' . $relative;
                if (!is_dir(dirname($target))) {
                    @mkdir(dirname($target), 0755, true);
                }
                @copy($rollbackDir . '/' . $relative, $target);
            }

            throw new \RuntimeException(
                $e->getMessage() . ' Файлы возвращены из снимка ' . basename($rollbackDir) . ' — проверьте сайт.',
                0,
                $e
            );
        } finally {
            // Сайт открывается в любом случае: и после успеха, и после отказа
            // с откатом. Закрытый сайт — это происшествие, а не деталь.
            UpdateState::releaseMaintenance();
        }

        return [
            'release' => $release,
            'copied' => $applied['copied'],
            'deleted' => $applied['deleted'],
            'migrations' => $migrated,
            'backup' => basename($backup),
        ];
    }

    /**
     * Пробный прогон: скачивает и проверяет архив, считает план замены — и
     * ничего не трогает. Нужен ровно затем, чтобы увидеть список файлов до
     * того, как они заменятся: «удалить устаревших: 340» — это повод
     * остановиться и посмотреть, а не нажимать дальше.
     *
     * @param array<string,mixed> $check результат Updater::check()
     * @param callable(string):void|null $echo
     * @return array{copy:list<string>, delete:list<string>}
     * @throws \RuntimeException
     */
    public static function preview(array $check, ?callable $echo = null): array
    {
        $asset = self::asset($check);
        $root = Updater::root();
        $report = static function (string $text) use ($echo): void {
            if ($echo !== null) {
                $echo($text);
            }
        };

        return self::prepare((string) ($check['latest'] ?? ''), $asset, self::workDir($root), $root, $report)['plan'];
    }

    private static function workDir(string $root): string
    {
        $work = $root . '/storage/updates';
        if (!is_dir($work) && !@mkdir($work, 0750, true) && !is_dir($work)) {
            throw new \RuntimeException('Не удалось создать каталог ' . $work);
        }

        return $work;
    }

    /**
     * Разбирает описание релиза в четыре строки: имя и адрес архива, имя и
     * адрес файла суммы. Один разбор на весь класс — дальше по коду ходят
     * строки, а не вложенные массивы неизвестной формы.
     *
     * @param array<string,mixed> $check
     * @return array{archive_name:string, archive_url:string, sum_name:string, sum_url:string}
     * @throws \RuntimeException
     */
    private static function asset(array $check): array
    {
        $asset = $check['asset'] ?? null;
        $archive = is_array($asset) ? ($asset['archive'] ?? null) : null;
        $sum = is_array($asset) ? ($asset['checksum'] ?? null) : null;
        if (!is_array($archive) || !is_array($sum)) {
            throw new \RuntimeException('У релиза нет установочного архива с контрольной суммой.');
        }

        return [
            'archive_name' => (string) ($archive['name'] ?? ''),
            'archive_url' => (string) ($archive['url'] ?? ''),
            'sum_name' => (string) ($sum['name'] ?? ''),
            'sum_url' => (string) ($sum['url'] ?? ''),
        ];
    }

    /**
     * Загрузка, сверка суммы, распаковка, проверка состава и план замены —
     * всё, что можно сделать, ничего ещё не меняя. Общее у обновления и
     * пробного прогона: разойдись они, пробный прогон показывал бы не то,
     * что потом произойдёт.
     *
     * @param array{archive_name:string, archive_url:string, sum_name:string, sum_url:string} $asset
     * @param callable(string):void $report
     * @return array{tree:string, plan:array{copy:list<string>, delete:list<string>}}
     * @throws \RuntimeException
     */
    private static function prepare(string $release, array $asset, string $work, string $root, callable $report): array
    {
        $zipPath = $work . '/' . basename($asset['archive_name']);
        $sumPath = $work . '/' . basename($asset['sum_name']);

        $size = Updater::download($asset['archive_url'], $zipPath);
        $report('Архив скачан: ' . number_format($size / 1048576, 2, '.', ' ') . ' МБ');

        Updater::download($asset['sum_url'], $sumPath);
        if (!Updater::verifyChecksum($zipPath, (string) file_get_contents($sumPath))) {
            throw new \RuntimeException('контрольная сумма архива не сошлась — загрузка повреждена или подменена.');
        }
        $report('Контрольная сумма SHA-256 сошлась');

        $treeDir = $work . '/tree-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $release);
        self::removeTree($treeDir);
        Updater::extract($zipPath, $treeDir);

        // git archive кладёт всё под общий префикс каталога.
        $inner = glob($treeDir . '/*', GLOB_ONLYDIR) ?: [];
        $newTree = count($inner) === 1 && !is_file($treeDir . '/public/index.php') ? $inner[0] : $treeDir;

        $problems = Updater::validateTree($newTree);
        if ($problems !== []) {
            throw new \RuntimeException('архив не похож на установочный: ' . implode('; ', $problems));
        }
        $report('Состав архива проверен');

        $plan = Updater::plan($newTree, $root);
        $report('План: заменить ' . count($plan['copy']) . ', удалить устаревших ' . count($plan['delete']));

        return ['tree' => $newTree, 'plan' => $plan];
    }

    /**
     * Миграции — в этом же процессе, а не отдельным `php database/migrate.php`.
     * Запуск дочернего процесса (`passthru`/`exec`) на shared-хостинге часто
     * запрещён, и обновление обрывалось бы ровно после замены файлов —
     * в самом неудачном месте.
     *
     * @param callable(string):void $report
     */
    private static function migrate(callable $report): int
    {
        if (!Database::isConnected()) {
            $report('База недоступна — миграции пропущены');

            return 0;
        }

        $applied = MigrationRunner::applyPending(
            Database::pdo(),
            Updater::root() . '/database/migrations'
        );

        return count($applied);
    }

    private static function clearPageCache(string $root): void
    {
        $dir = $root . '/storage/cache/page';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $item) {
            if (is_file($item)) {
                @unlink($item);
            }
        }
    }

    /**
     * Рекурсивное удаление средствами PHP: запуск внешней команды оболочки
     * на shared-хостинге бывает запрещён, а каталог убрать надо.
     */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
