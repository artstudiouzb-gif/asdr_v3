<?php

use App\Core\Csrf;

$pageTitle = 'История изменений блока';

$fmtDate = static function (?string $dt): string {
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);

    return $ts !== false ? date('d.m.Y H:i', $ts) : $dt;
};
$activeNav = 'pages';
require __DIR__ . '/../layout/header.php';

/** @var array $block */
/** @var array $revisions */
/** @var string $backUrl */

$typeLabel = htmlspecialchars((string) $block['type'], ENT_QUOTES);
$blockName = $block['title'] !== null && $block['title'] !== ''
    ? htmlspecialchars((string) $block['title'], ENT_QUOTES)
    : ('#' . (int) $block['id'] . ' (' . $typeLabel . ')');
?>
<a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn--small u-inline-79a1c5a5db">&larr; Назад к странице</a>

<h1 class="u-inline-291b7bbb01">История версий блока: <?= $blockName ?></h1>
<p class="admin-hint">
    Хранятся последние 20 версий. Восстановление применяет выбранную версию
    и само создаётся как новая запись истории (текущее состояние не теряется).
</p>

<table class="data-table">
    <thead>
        <tr><th>Дата</th><th>Автор</th><th>Название</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (empty($revisions)): ?>
            <tr><td colspan="4" class="data-table__empty">История пуста — блок ещё ни разу не пересохраняли.</td></tr>
        <?php endif; ?>
        <?php foreach ($revisions as $rev): ?>
            <tr>
                <td><?= htmlspecialchars($fmtDate((string) $rev['created_at']), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($rev['author'] ?? '—'), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) ($rev['title'] ?? '—'), ENT_QUOTES) ?></td>
                <td class="data-table__actions">
                    <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/revisions/restore"
                          data-confirm="Восстановить блок из этой версии? Текущее состояние сохранится в истории.">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="revision_id" value="<?= (int) $rev['id'] ?>">
                        <input type="hidden" name="expected_lock_version" value="<?= (int) ($block['lock_version'] ?? 1) ?>">
                        <button class="btn btn--small btn--primary"><?= \App\Core\AdminUi::icon('reset') ?>Восстановить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../layout/footer.php'; ?>