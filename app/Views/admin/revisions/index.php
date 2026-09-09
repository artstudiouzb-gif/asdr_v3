<?php

use App\Core\Csrf;

/** @var string $entityType */
/** @var string $entityLabel */
/** @var string $editUrl */
/** @var array<string,mixed> $entity */
/** @var array<int,array<string,mixed>> $revisions */
$entityType = $entityType ?? '';
$entityLabel = $entityLabel ?? '';
$editUrl = $editUrl ?? '#';
$entity = $entity ?? [];
$revisions = $revisions ?? [];

$pageTitle = 'История ' . $entityLabel;
$activeNav = match ($entityType) {
    'page' => 'pages',
    'news' => 'news',
    'project' => 'projects',
    default => '',
};
require __DIR__ . '/../layout/header.php';
?>
<div class="admin-page-head">
    <div>
        <h1>История <?= htmlspecialchars($entityLabel, ENT_QUOTES) ?></h1>
        <p class="form-hint"><?= htmlspecialchars((string) ($entity['title'] ?? ''), ENT_QUOTES) ?></p>
    </div>
    <a class="btn" href="<?= htmlspecialchars($editUrl, ENT_QUOTES) ?>">← Вернуться к редактированию</a>
</div>

<div class="form-card">
    <?php if ($revisions === []): ?>
        <p>История пока пуста. Первая версия появится перед следующим сохранением.</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Дата</th><th>Автор</th><th>Действие</th></tr></thead>
            <tbody>
            <?php foreach ($revisions as $revision): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $revision['created_at'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) $revision['username'], ENT_QUOTES) ?></td>
                    <td>
                        <form method="post" action="/admin/revisions/<?= rawurlencode($entityType) ?>/<?= (int) $entity['id'] ?>/<?= (int) $revision['id'] ?>/restore" data-confirm="Восстановить эту версию? Текущее состояние останется в истории.">
                            <?= Csrf::field() ?>
                            <button class="btn btn--small" type="submit">Восстановить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>