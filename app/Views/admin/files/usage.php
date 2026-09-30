<?php

use App\Core\AdminUi;

/** @var array<string, mixed> $file */
/** @var list<array{label: string, url: string, trashed: bool, table: string}> $usage */

$pageActions = '<a href="/admin/files" class="btn">' . AdminUi::icon('arrow-left') . 'К медиатеке</a>';
$pageTitle = 'Где используется файл';
$activeNav = 'files';
require __DIR__ . '/../layout/header.php';
?>
<p class="form-hint">
    Файл <strong><?= htmlspecialchars((string) $file['original_name'], ENT_QUOTES) ?></strong>
    (<code><?= htmlspecialchars((string) $file['stored_name'], ENT_QUOTES) ?></code>).
    <?php if ($usage === []): ?>
        Ни одна запись сайта на него не ссылается: его можно удалить.
    <?php else: ?>
        Пока он стоит хотя бы в одном месте, удалить его нельзя: запись осталась бы с пустой картинкой или битой ссылкой.
        Замените файл в этих местах или уберите его, потом возвращайтесь к удалению.
    <?php endif; ?>
</p>

<?php if ($usage !== []): ?>
    <table class="data-table">
        <thead>
            <tr><th>Где</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($usage as $place): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($place['label'], ENT_QUOTES) ?>
                        <?php if ($place['trashed']): ?>
                            <span class="badge badge--draft">в корзине</span>
                        <?php endif; ?>
                    </td>
                    <td class="data-table__actions">
                        <?php if ($place['url'] !== ''): ?>
                            <a href="<?= htmlspecialchars($place['url'], ENT_QUOTES) ?>" class="btn btn--small"><?= AdminUi::icon('edit') ?>Открыть</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="form-hint">История версий файл не удерживает: иначе однажды использованный файл нельзя было бы удалить никогда. Запись в корзине — удерживает: её ещё можно восстановить.</p>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
