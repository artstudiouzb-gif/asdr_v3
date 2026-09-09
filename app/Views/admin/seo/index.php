<?php
/**
 * Раздел «Поиск и индексация»: результат последней проверки и её история.
 *
 * @var array{id: int, errors: int, warnings: int, created_at: string, findings: list<\App\Core\Seo\SeoFinding>}|null $latest
 * @var list<array{id: int, errors: int, warnings: int, created_at: string}> $history
 */

use App\Core\AdminUi;
use App\Core\Seo\SeoFinding;

$pageTitle = 'Поиск и индексация';
$activeNav = 'seo';
require __DIR__ . '/../layout/header.php';

$levelLabel = ['error' => 'Ошибка', 'warning' => 'Внимание', 'ok' => 'В порядке'];
$levelBadge = ['error' => 'badge--danger', 'warning' => 'badge--warning', 'ok' => 'badge--success'];
?>
<?php // Заголовок раздела печатает макет по $pageTitle — второй такой же
      // читался бы как ошибка вёрстки. ?>
<div class="admin-page-actions">
    <form method="post" action="/admin/seo/run">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn--primary"><?= AdminUi::icon('refresh') ?>Проверить сейчас</button>
    </form>
</div>

<div class="settings-card">
    <p class="settings-card__subtitle">
        Проверка отвечает на вопрос «почему страница может не попасть в поиск». Google и Яндекс
        показывают следствие с задержкой в сутки-трое, а причина — запрет индексации на живом адресе,
        редирект поверх страницы, оборванная карта сайта — видна здесь сразу.
        Запускается по расписанию (<code>app/Console/seo_worker.php</code>) и этой кнопкой.
    </p>
</div>

<?php if ($latest === null): ?>
    <div class="alert alert--info">Проверка ещё не выполнялась. Нажмите «Проверить сейчас».</div>
<?php else: ?>
    <div class="settings-card">
        <h2>Последняя проверка</h2>
        <p class="settings-card__subtitle">
            <?= htmlspecialchars($latest['created_at'], ENT_QUOTES) ?> —
            ошибок: <strong><?= (int) $latest['errors'] ?></strong>,
            предупреждений: <strong><?= (int) $latest['warnings'] ?></strong>.
        </p>

        <table class="data-table">
            <thead>
                <tr><th>Состояние</th><th>Проверка</th><th>Что это значит</th></tr>
            </thead>
            <tbody>
                <?php foreach ($latest['findings'] as $finding): ?>
                    <tr>
                        <td>
                            <span class="badge <?= $levelBadge[$finding->level] ?? 'badge--warning' ?>">
                                <?= htmlspecialchars($levelLabel[$finding->level] ?? $finding->level, ENT_QUOTES) ?>
                            </span>
                        </td>
                        <td>
                            <?= htmlspecialchars($finding->title, ENT_QUOTES) ?>
                            <?php if ($finding->count > 0 && $finding->level !== SeoFinding::LEVEL_OK): ?>
                                <span class="badge">Затронуто: <?= (int) $finding->count ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($finding->detail !== ''): ?>
                                <div><?= htmlspecialchars($finding->detail, ENT_QUOTES) ?></div>
                            <?php endif; ?>
                            <?php if ($finding->samples !== []): ?>
                                <ul class="seo-samples">
                                    <?php foreach ($finding->samples as $sample): ?>
                                        <li><?= htmlspecialchars($sample, ENT_QUOTES) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($finding->fixUrl !== ''): ?>
                                <a href="<?= htmlspecialchars($finding->fixUrl, ENT_QUOTES) ?>">Открыть</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($history !== []): ?>
    <div class="settings-card">
        <h2>История</h2>
        <p class="settings-card__subtitle">
            Первый вопрос по любой находке — «это новое или так было всегда». История отвечает на него.
        </p>
        <table class="data-table">
            <thead><tr><th>Когда</th><th>Ошибок</th><th>Предупреждений</th></tr></thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES) ?></td>
                        <td><?= (int) $row['errors'] ?></td>
                        <td><?= (int) $row['warnings'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
