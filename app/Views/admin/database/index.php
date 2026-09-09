<?php

use App\Core\AdminUi;
use App\Core\Csrf;

$pageTitle = 'База данных и миграции';
$activeNav = 'database';
require __DIR__ . '/../layout/header.php';

/** @var array $status */
/** @var string $dbName */
/** @var string $dbVersion */
/** @var array<int, array<string, mixed>> $tables */
/** @var float $totalSizeMb */
/** @var array<string, mixed>|null $doctorReport */

$pendingCount = (int) ($status['pending_count'] ?? 0);
$appliedCount = (int) ($status['applied_count'] ?? 0);
$totalMigrations = (int) ($status['total'] ?? 0);
?>

<p class="form-hint admin-section-intro">
    Управление структурой базы данных, накатывание обновлений (миграций), мониторинг таблиц и диагностика целостности.
</p>

<nav class="settings-jump-nav" aria-label="Разделы базы данных">
    <a href="#db-migrations"><?= AdminUi::icon('database', 16) ?>Миграции <?= $pendingCount > 0 ? '<span class="badge badge--danger">' . $pendingCount . '</span>' : '' ?></a>
    <a href="#db-metrics"><?= AdminUi::icon('chart-bar', 16) ?>Метрики и таблицы</a>
    <a href="#db-diagnostics"><?= AdminUi::icon('shield', 16) ?>Диагностика и обслуживание</a>
</nav>

<!-- Блок статуса миграций -->
<div class="form-card admin-mt-24" id="db-migrations">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('database', 22) ?></div>
        <div>
            <h3 class="settings-card__title">Статус обновлений структуры БД</h3>
            <p class="settings-card__subtitle">
                Все изменения в схеме базы данных хранятся в виде версионированных SQL-миграций.
            </p>
        </div>
    </div>

    <?php if ($pendingCount > 0): ?>
        <div class="alert alert--warning admin-mt-16">
            <div class="alert__content">
                <strong>Доступны новые обновления базы данных!</strong><br>
                Обнаружено <strong><?= $pendingCount ?></strong> неприменённых <?= $pendingCount === 1 ? 'миграция' : 'миграций' ?>. Рекомендуется применить их для корректной работы всех функций сайта.
            </div>
        </div>

        <div class="admin-mt-16">
            <h4>Список ожидающих миграций:</h4>
            <ul class="admin-list-group admin-mt-8">
                <?php foreach (($status['pending'] ?? []) as $p): ?>
                    <li class="admin-list-group__item">
                        <span class="badge badge--warning">Ожидает</span>
                        <code class="admin-ml-8"><?= htmlspecialchars((string) $p['name'], ENT_QUOTES) ?></code>
                        <span class="text-muted admin-ml-8">(<?= number_format(((int) $p['size']) / 1024, 1, ',', ' ') ?> КиБ)</span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="post" action="/admin/database/migrate" class="admin-mt-16"
                  data-confirm="Применить все новые миграции базы данных? Перед обновлением на production рекомендуется иметь свежий бэкап.">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary btn--large">
                    <?= AdminUi::icon('check', 18) ?> Применить все обновления базы данных (<?= $pendingCount ?>)
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="alert alert--success admin-mt-16">
            <div class="alert__content">
                <strong>База данных полностью актуальна.</strong><br>
                Все <?= $totalMigrations ?> зарегистрированных миграций успешно применены.
            </div>
        </div>
    <?php endif; ?>

    <!-- История применённых миграций -->
    <details class="admin-mt-24">
        <summary class="btn btn--outline btn--small">
            <?= AdminUi::icon('history', 15) ?> Показать историю применённых миграций (<?= $appliedCount ?>)
        </summary>
        <div class="admin-mt-12">
            <table class="table table--compact table--striped">
                <thead>
                    <tr>
                        <th>Файл миграции</th>
                        <th>Дата применения</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($status['applied'] ?? []) as $a): ?>
                        <tr>
                            <td><code><?= htmlspecialchars((string) $a['name'], ENT_QUOTES) ?></code></td>
                            <td><?= htmlspecialchars((string) ($a['applied_at'] ?: '—'), ENT_QUOTES) ?></td>
                            <td><span class="badge badge--success">Применена</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</div>

<!-- Метрики и таблицы -->
<div class="form-card admin-mt-24" id="db-metrics">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('chart-bar', 22) ?></div>
        <div>
            <h3 class="settings-card__title">Метрики и таблицы базы данных</h3>
            <p class="settings-card__subtitle">Информация о сервере MySQL, структуре и объёме занимаемой памяти на диске.</p>
        </div>
    </div>

    <div class="admin-grid admin-grid--4 admin-mt-16">
        <div class="stat-card">
            <div class="stat-card__label">СУБД / Версия</div>
            <div class="stat-card__value stat-card__value--text"><?= htmlspecialchars($dbVersion, ENT_QUOTES) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card__label">Имя базы данных</div>
            <div class="stat-card__value stat-card__value--text"><?= htmlspecialchars($dbName, ENT_QUOTES) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card__label">Количество таблиц</div>
            <div class="stat-card__value"><?= count($tables) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-card__label">Общий объём данных</div>
            <div class="stat-card__value"><?= $totalSizeMb ?> МБ</div>
        </div>
    </div>

    <details class="admin-mt-24">
        <summary class="btn btn--outline btn--small">
            <?= AdminUi::icon('list', 15) ?> Показать структуру всех таблиц (<?= count($tables) ?>)
        </summary>
        <div class="admin-mt-12">
            <table class="table table--compact table--striped">
                <thead>
                    <tr>
                        <th>Имя таблицы</th>
                        <th>Движок</th>
                        <th>Строк (примерно)</th>
                        <th>Размер</th>
                        <th>Кодировка / Collation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tables as $t): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string) ($t['name'] ?? ''), ENT_QUOTES) ?></strong></td>
                            <td><?= htmlspecialchars((string) ($t['engine'] ?? 'InnoDB'), ENT_QUOTES) ?></td>
                            <td><?= number_format((int) ($t['rows_count'] ?? 0), 0, '', ' ') ?></td>
                            <td><?= htmlspecialchars((string) ($t['size_mb'] ?? '0'), ENT_QUOTES) ?> МБ</td>
                            <td><?= htmlspecialchars((string) ($t['collation'] ?? 'utf8mb4_unicode_ci'), ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</div>

<!-- Диагностика и обслуживание -->
<div class="form-card admin-mt-24" id="db-diagnostics">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('shield', 22) ?></div>
        <div>
            <h3 class="settings-card__title">Диагностика и обслуживание</h3>
            <p class="settings-card__subtitle">Автоматическая сверка схемы с эталоном, проверка связей внешних ключей и оптимизация индексов.</p>
        </div>
    </div>

    <div class="admin-actions-bar admin-mt-16">
        <a href="/admin/database?diagnose=1#db-diagnostics" class="btn btn--outline">
            <?= AdminUi::icon('search', 16) ?> Запустить диагностику БД (Doctor)
        </a>
        <form method="post" action="/admin/database/optimize" data-confirm="Оптимизировать и дефрагментировать таблицы базы данных?">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--outline">
                <?= AdminUi::icon('tool', 16) ?> Оптимизировать таблицы
            </button>
        </form>
        <form method="post" action="/admin/backup">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--outline">
                <?= AdminUi::icon('save', 16) ?> Скачать полный бэкап (.zip)
            </button>
        </form>
    </div>

    <?php if ($doctorReport !== null): ?>
        <div class="admin-mt-24">
            <h4>Отчёт встроенной диагностики (Database Doctor):</h4>
            <div class="admin-mt-8">
                <?php if ($doctorReport['is_healthy']): ?>
                    <div class="alert alert--success">
                        <strong>Все проверки пройдены успешно!</strong> Ошибок схемы и повреждений данных не обнаружено.
                    </div>
                <?php else: ?>
                    <div class="alert alert--danger">
                        <strong>Обнаружены несоответствия в базе данных!</strong> Ошибок: <?= (int) $doctorReport['errors_count'] ?>, предупреждений: <?= (int) $doctorReport['warnings_count'] ?>.
                    </div>
                <?php endif; ?>

                <ul class="admin-list-group admin-mt-12">
                    <?php foreach (($doctorReport['checks'] ?? []) as $check): ?>
                        <li class="admin-list-group__item">
                            <?php if ($check['type'] === 'ok'): ?>
                                <span class="badge badge--success"><?= AdminUi::icon('check', 12) ?> OK</span>
                            <?php elseif ($check['type'] === 'warning'): ?>
                                <span class="badge badge--warning"><?= AdminUi::icon('alert-triangle', 12) ?> Внимание</span>
                            <?php else: ?>
                                <span class="badge badge--danger"><?= AdminUi::icon('x', 12) ?> Ошибка</span>
                            <?php endif; ?>
                            <span class="admin-ml-8"><?= htmlspecialchars((string) $check['message'], ENT_QUOTES) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
