<?php
/**
 * Раздел «Проверка ссылок»: битые ссылки в контенте и места, где их чинить.
 *
 * @var list<array{url: string, state: string, status: int, error: string, checked_at: string, first_failed_at: string, places: list<array{label: string, url: string, trashed: bool, table: string}>}> $problems
 * @var array<string, int> $counts
 * @var string|null $lastChecked
 * @var int|null $workerLastRun
 */

use App\Core\AdminUi;
use App\Core\LinkChecker;

$pageTitle = 'Проверка ссылок';
$activeNav = 'links';
require __DIR__ . '/../layout/header.php';

$stateLabel = [
    LinkChecker::STATE_BROKEN => 'Битая',
    LinkChecker::STATE_UNREACHABLE => 'Не отвечает',
    LinkChecker::STATE_BLOCKED => 'Проверьте вручную',
];
$stateBadge = [
    LinkChecker::STATE_BROKEN => 'badge--danger',
    LinkChecker::STATE_UNREACHABLE => 'badge--warning',
    LinkChecker::STATE_BLOCKED => 'badge--muted',
];
?>
<div class="admin-page-actions">
    <form method="post" action="/admin/links/run">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn--primary"><?= AdminUi::icon('refresh') ?>Проверить внутренние ссылки</button>
    </form>
</div>

<div class="settings-card">
    <p class="settings-card__subtitle">
        Журнал 404 видит битую ссылку, только когда по ней уже пришёл посетитель. Здесь проверяются
        ссылки, которые стоят в текстах, блоках, меню и настройках: на удалённую страницу, на файл,
        которого нет, на чужой сайт, который закрылся. У каждой — где она стоит и ссылка на эту запись.
    </p>
    <p class="settings-card__subtitle">
        Кнопка проверяет ссылки на свой сайт. Внешние ссылки проверяет расписание
        (<code>app/Console/link_worker.php</code>, раз в сутки): чужие серверы отвечают медленно,
        и ждать их в окне браузера нельзя. Внешний сайт, не ответивший один раз, битым не считается —
        только после <?= LinkChecker::UNREACHABLE_STREAK ?> отказов подряд.
    </p>
</div>

<?php if ($workerLastRun === null): ?>
    <div class="alert alert--info">Внешние ссылки ещё ни разу не проверялись: расписание <code>link_worker.php</code> не настроено.</div>
<?php endif; ?>

<?php if ($lastChecked === null): ?>
    <div class="alert alert--info">Проверка ещё не выполнялась. Нажмите «Проверить внутренние ссылки».</div>
<?php elseif ($problems === []): ?>
    <div class="alert alert--success">Битых ссылок не найдено. Последняя проверка — <?= htmlspecialchars($lastChecked, ENT_QUOTES) ?>.</div>
<?php else: ?>
    <p class="form-hint">
        Битых: <strong><?= (int) $counts[LinkChecker::STATE_BROKEN] ?></strong>,
        не отвечают: <strong><?= (int) $counts[LinkChecker::STATE_UNREACHABLE] ?></strong>,
        проверить вручную: <strong><?= (int) $counts[LinkChecker::STATE_BLOCKED] ?></strong>.
        Последняя проверка — <?= htmlspecialchars($lastChecked, ENT_QUOTES) ?>.
    </p>
    <table class="data-table">
        <thead>
            <tr><th>Ссылка</th><th>Ответ</th><th>Где стоит</th></tr>
        </thead>
        <tbody>
            <?php foreach ($problems as $item): ?>
                <tr>
                    <td>
                        <code><?= htmlspecialchars($item['url'], ENT_QUOTES) ?></code>
                        <?php if ($item['first_failed_at'] !== ''): ?>
                            <div class="form-hint">Не работает с <?= htmlspecialchars($item['first_failed_at'], ENT_QUOTES) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $stateBadge[$item['state']] ?? 'badge--muted' ?>"><?= htmlspecialchars($stateLabel[$item['state']] ?? $item['state'], ENT_QUOTES) ?></span>
                        <div class="form-hint"><?= htmlspecialchars($item['error'] !== '' ? $item['error'] : ('Код ' . $item['status']), ENT_QUOTES) ?></div>
                    </td>
                    <td>
                        <?php if ($item['places'] === []): ?>
                            <span class="form-hint">Ссылка уже убрана из контента — исчезнет при следующей проверке.</span>
                        <?php endif; ?>
                        <?php foreach ($item['places'] as $place): ?>
                            <div>
                                <?php if ($place['url'] !== ''): ?>
                                    <a href="<?= htmlspecialchars($place['url'], ENT_QUOTES) ?>"><?= htmlspecialchars($place['label'], ENT_QUOTES) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($place['label'], ENT_QUOTES) ?>
                                <?php endif; ?>
                                <?php if ($place['trashed']): ?>
                                    <span class="badge badge--draft">в корзине</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
