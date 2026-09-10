<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Core\LogReader;

$pageTitle = 'Журнал ошибок';
$activeNav = 'logs';

/** @var string $channel */
/** @var array<string, array{title:string, exists:bool, size:int, mtime:?int}> $channels */
/** @var array{groups: list<array{level:string, message:string, count:int, first:string, last:string}>, total:int, size:int, truncated:bool, unparsed:int} $data */

$levelBadge = [
    'CRITICAL' => 'badge--danger',
    'ERROR' => 'badge--danger',
    'WARNING' => 'badge--draft',
    'SECURITY' => 'badge--danger',
    'DEPRECATED' => 'badge--draft',
];
?>
<p class="form-hint admin-section-intro">
    Одинаковые записи сведены в одну строку с числом повторов: чинить нужно то, что повторяется,
    а не то, что случилось однажды. Дословно разные записи не объединяются — две поломки не должны
    выглядеть одной.
</p>

<nav class="settings-jump-nav" aria-label="Журналы">
    <?php foreach ($channels as $key => $info): ?>
        <a href="/admin/logs?channel=<?= urlencode($key) ?>"<?= $key === $channel ? ' class="is-active"' : '' ?>>
            <?= htmlspecialchars($info['title'], ENT_QUOTES) ?>
            <?php if ($info['exists'] && $info['size'] > 0): ?>
                <span class="lang-filter-count"><?= htmlspecialchars(LogReader::formatSize($info['size']), ENT_QUOTES) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="form-card">
    <?= AdminUi::cardHeader($channels[$channel]['title'] ?? 'Журнал', 'alert-triangle') ?>

    <?php if ($data['total'] === 0): ?>
        <p class="form-hint">Записей нет — это хорошая новость.</p>
    <?php else: ?>
        <p class="form-hint">
            Разобрано записей: <strong><?= (int) $data['total'] ?></strong>,
            различных: <strong><?= count($data['groups']) ?></strong>.
            <?php if ($data['truncated']): ?>
                Файл большой (<?= htmlspecialchars(LogReader::formatSize($data['size']), ENT_QUOTES) ?>),
                поэтому показан только его конец — самые свежие записи.
            <?php endif; ?>
            <?php if ($data['unparsed'] > 0): ?>
                Строк не нашего формата: <?= (int) $data['unparsed'] ?> (продолжение стека PHP).
            <?php endif; ?>
        </p>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Уровень</th>
                        <th>Повторов</th>
                        <th>Сообщение</th>
                        <th>Последний раз</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['groups'] as $row): ?>
                        <tr>
                            <td>
                                <span class="badge <?= htmlspecialchars($levelBadge[$row['level']] ?? 'badge--draft', ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($row['level'], ENT_QUOTES) ?>
                                </span>
                            </td>
                            <td><strong><?= (int) $row['count'] ?></strong></td>
                            <td class="logrow__message"><?= htmlspecialchars($row['message'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['last'], ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" action="/admin/logs/clear" class="form-actions"
              data-confirm="Очистить журнал «<?= htmlspecialchars($channels[$channel]['title'] ?? '', ENT_QUOTES) ?>» целиком? Записи будут удалены безвозвратно.">
            <?= Csrf::field() ?>
            <input type="hidden" name="channel" value="<?= htmlspecialchars($channel, ENT_QUOTES) ?>">
            <button type="submit" class="btn btn--danger"><?= AdminUi::icon('trash') ?>Очистить журнал</button>
        </form>
    <?php endif; ?>
</section>
