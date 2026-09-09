<?php

use App\Core\Csrf;

$pageTitle = 'Журнал ошибок';
$activeNav = 'audit';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array $filters */

// Query-строка текущих фильтров для ссылок пагинации.
$qs = static function (int $p) use ($filters): string {
    $params = array_filter([
        'level' => $filters['level'] !== '' ? $filters['level'] : null,
        'q' => $filters['q'] !== '' ? $filters['q'] : null,
        'page' => $p > 1 ? $p : null,
    ]);
    return $params === [] ? '/admin/audit/errors' : '/admin/audit/errors?' . http_build_query($params);
};

// Короткий путь файла без корня проекта — чтобы колонка «Где» читалась.
$shortFile = static function (string $file): string {
    $root = str_replace('\\', '/', dirname(__DIR__, 3)) . '/';
    return str_replace([$root, '\\'], ['', '/'], str_replace('\\', '/', $file));
};
?>
<div class="u-inline-f94566b02a">
    <a class="btn btn--small" href="/admin/security">Центр безопасности</a>
    <a class="btn btn--small" href="/admin/audit">Действия администраторов</a>
    <a class="btn btn--small btn--primary" href="/admin/audit/errors">Ошибки сайта</a>
</div>

<p class="form-hint">Ошибки, перехваченные на сайте и в панели: что случилось, где и почему — понятным языком. Технические детали раскрываются по клику. Записи старше <?= (int) \App\Models\ErrorLog::RETENTION_DAYS ?> дней удаляются автоматически, либо очистите журнал вручную.</p>

<div class="u-inline-f45a3bfb9e">
    <form class="u-inline-c971a09486" method="get" action="/admin/audit/errors">
        <div class="form-field u-inline-1da9facb4d">
            <label for="f_level">Уровень</label>
            <select id="f_level" name="level">
                <option value="">— все —</option>
                <?php foreach (['ERROR' => 'Ошибка', 'CRITICAL' => 'Критическая'] as $lv => $lvLabel): ?>
                    <option value="<?= $lv ?>" <?= strtoupper($filters['level']) === $lv ? 'selected' : '' ?>><?= $lvLabel ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field u-inline-1da9facb4d">
            <label for="f_q">Текст, файл или адрес содержит</label>
            <input type="text" id="f_q" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="например: SQLSTATE или /news">
        </div>
        <div class="form-actions u-inline-1da9facb4d">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('filter') ?>Фильтровать</button>
            <a href="/admin/audit/errors" class="btn"><?= \App\Core\AdminUi::icon('reset') ?>Сбросить</a>
        </div>
    </form>
    <?php if ($total > 0): ?>
        <form class="u-inline-1da9facb4d" method="post" action="/admin/audit/errors/clear" data-confirm="Очистить журнал ошибок полностью?">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--danger">Очистить журнал</button>
        </form>
    <?php endif; ?>
</div>

<p class="form-hint">Найдено записей: <strong><?= (int) $total ?></strong></p>

<?php if (empty($items)): ?>
    <p class="form-hint">Ошибок нет — сайт работает штатно. Записи появляются здесь автоматически, когда на сайте или в панели что-то ломается.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Когда</th><th>Уровень</th><th>Что случилось и почему</th><th>Где</th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td class="u-inline-df7cae6aa0"><?= htmlspecialchars((string) $item['created_at'], ENT_QUOTES) ?></td>
                    <td class="u-inline-060dc9f317">
                        <?php $critical = strtoupper((string) $item['level']) === 'CRITICAL'; ?>
                        <span class="audit-severity <?= $critical ? 'audit-severity--critical' : 'audit-severity--warning' ?>">
                            <?= $critical ? 'Критическая' : 'Ошибка' ?>
                        </span>
                    </td>
                    <td class="u-inline-060dc9f317">
                        <?= htmlspecialchars((string) $item['human'], ENT_QUOTES) ?>
                        <details class="u-inline-2fd7789b39">
                            <summary class="u-inline-bbb9c7d040">Технические детали</summary>
                            <code class="u-inline-0dfd020469"><?= htmlspecialchars((string) $item['message'], ENT_QUOTES) ?></code>
                        </details>
                    </td>
                    <td class="u-inline-6c1edda8b7">
                        <?php if ((string) $item['file'] !== ''): ?>
                            <code class="u-inline-1d77e476f8"><?= htmlspecialchars($shortFile((string) $item['file']) . ':' . (int) $item['line'], ENT_QUOTES) ?></code><br>
                        <?php endif; ?>
                        <span class="u-inline-6a8c41db55">Страница: <?= htmlspecialchars((string) $item['url'], ENT_QUOTES) ?></span>
                        <?php if (!empty($item['ip'])): ?><br><span class="u-inline-6a8c41db55">IP: <?= htmlspecialchars((string) $item['ip'], ENT_QUOTES) ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="u-inline-d2576ac843">
            <?php if ($page > 1): ?><a class="btn btn--small" href="<?= htmlspecialchars($qs($page - 1), ENT_QUOTES) ?>">← Новее</a><?php endif; ?>
            <span class="form-hint">Страница <?= (int) $page ?> из <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn--small" href="<?= htmlspecialchars($qs($page + 1), ENT_QUOTES) ?>">Старее →</a><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
