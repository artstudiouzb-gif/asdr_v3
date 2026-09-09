<?php
/**
 * Раздел «Обновление системы»: что стоит, что вышло и как идёт замена.
 *
 * Страница ничего не выполняет — она заказывает и показывает. Саму замену
 * делает app/Console/update_worker.php (см. Admin\UpdateController).
 *
 * @var array{status:string, release:string, requested_by:string, requested_at:int,
 *     started_at:int, finished_at:int, heartbeat:int, step:string, error:string,
 *     log:list<array{at:int,level:string,text:string}>, maintenance_owned:bool,
 *     maintenance_before:string, files_touched:bool} $state
 * @var bool $stale
 * @var array<string,mixed> $check
 * @var string $repo
 * @var array{last:?int, age:?int, alive:bool} $worker
 * @var string $confirmCode
 * @var string $cronLine
 */

use App\Core\AdminUi;
use App\Core\UpdateState;

$pageTitle = 'Обновление системы';
$activeNav = 'update';
require __DIR__ . '/../layout/header.php';

$busy = in_array($state['status'], [UpdateState::STATUS_QUEUED, UpdateState::STATUS_RUNNING], true) && !$stale;
$moment = static fn (int $ts): string => $ts > 0 ? date('d.m.Y H:i:s', $ts) : '—';
$ago = static function (?int $seconds): string {
    if ($seconds === null) {
        return 'ни разу';
    }

    return $seconds < 60 ? ($seconds . ' с назад') : (int) round($seconds / 60) . ' мин назад';
};
?>

<div class="settings-card">
    <p class="settings-card__subtitle">
        Обновление ставится из релиза на GitHub — репозиторий <code><?= htmlspecialchars($repo, ENT_QUOTES) ?></code>
        (задаётся переменной окружения <code>UPDATE_REPO</code>, не настройкой в панели).
        Ставится только собранный архив <code>asdr-cms-*.zip</code> вместе с его <code>.sha256</code>.
        Данные сайта неприкосновенны: <code>config/config.php</code>, <code>storage/</code>
        и <code>public/uploads/</code> не заменяются и не удаляются.
    </p>
    <p class="settings-card__subtitle">
        Кнопка ставит задачу, а выполняет её фоновый воркер из командной строки: веб-запрос
        обрывается по таймауту, и обрыв посреди замены оставил бы половину старых файлов
        и половину новых. На время замены файлов и миграций сайт закрывается на обслуживание,
        после — открывается сам. Перед заменой снимается полная резервная копия.
    </p>
</div>

<?php // --- Версии ---------------------------------------------------------- ?>
<div class="settings-card">
    <h2>Версии</h2>
    <table class="data-table">
        <tbody>
            <tr>
                <th class="u-inline-7006b2a9a3">Установлено</th>
                <td><strong><?= htmlspecialchars((string) ($check['installed'] ?? '—'), ENT_QUOTES) ?></strong></td>
            </tr>
            <?php if ($check['ok'] ?? false): ?>
                <tr>
                    <th>Последний релиз</th>
                    <td>
                        <strong><?= htmlspecialchars((string) ($check['latest'] ?? ''), ENT_QUOTES) ?></strong>
                        <?php if (($check['published_at'] ?? '') !== ''): ?>
                            <span class="badge"><?= htmlspecialchars(substr((string) $check['published_at'], 0, 10), ENT_QUOTES) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Состояние</th>
                    <td>
                        <?php if (!($check['available'] ?? false)): ?>
                            <span class="badge badge--success">Установлена последняя версия</span>
                        <?php elseif (!($check['installable'] ?? false)): ?>
                            <span class="badge badge--warning">Обновление недоступно</span>
                            <div><?= htmlspecialchars((string) ($check['reason'] ?? ''), ENT_QUOTES) ?></div>
                        <?php else: ?>
                            <span class="badge badge--accent">Доступно обновление</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <tr>
                    <th>Последний релиз</th>
                    <td>
                        <span class="badge badge--danger">Не удалось спросить GitHub</span>
                        <div><?= htmlspecialchars((string) ($check['error'] ?? ''), ENT_QUOTES) ?></div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php // --- Воркер ---------------------------------------------------------- ?>
<div class="settings-card">
    <h2>Фоновый воркер</h2>
    <?php if ($worker['alive']): ?>
        <p class="settings-card__subtitle">
            <span class="badge badge--success">Отвечает</span>
            последний запуск: <?= htmlspecialchars($moment((int) $worker['last']), ENT_QUOTES) ?>
            (<?= htmlspecialchars($ago($worker['age']), ENT_QUOTES) ?>).
        </p>
    <?php else: ?>
        <div class="alert alert--warning">
            Воркер не отвечает (<?= htmlspecialchars($ago($worker['age']), ENT_QUOTES) ?>), поэтому обновление
            заказать нельзя: задачу некому выполнить. Заведите задание cron — раз в минуту:
        </div>
        <pre><code><?= htmlspecialchars($cronLine, ENT_QUOTES) ?></code></pre>
    <?php endif; ?>
</div>

<?php // --- Ход обновления --------------------------------------------------- ?>
<?php if ($state['status'] !== UpdateState::STATUS_IDLE): ?>
    <div class="settings-card">
        <h2>Последнее обновление</h2>
        <p class="settings-card__subtitle">
            <?php if ($stale): ?>
                <span class="badge badge--danger">Оборвалось</span>
            <?php elseif ($state['status'] === UpdateState::STATUS_RUNNING): ?>
                <span class="badge badge--accent">Выполняется</span> <?= htmlspecialchars($state['step'], ENT_QUOTES) ?>
            <?php elseif ($state['status'] === UpdateState::STATUS_QUEUED): ?>
                <span class="badge badge--accent">В очереди</span>
            <?php elseif ($state['status'] === UpdateState::STATUS_DONE): ?>
                <span class="badge badge--success">Завершено</span>
            <?php else: ?>
                <span class="badge badge--danger">Остановлено</span>
            <?php endif; ?>
            <?php if ($state['release'] !== ''): ?>
                — версия <?= htmlspecialchars($state['release'], ENT_QUOTES) ?>,
            <?php endif; ?>
            заказал <?= htmlspecialchars($state['requested_by'] !== '' ? $state['requested_by'] : '—', ENT_QUOTES) ?>
            <?= htmlspecialchars($moment($state['requested_at']), ENT_QUOTES) ?>.
        </p>

        <?php if ($state['error'] !== ''): ?>
            <div class="alert alert--danger"><?= htmlspecialchars($state['error'], ENT_QUOTES) ?></div>
        <?php endif; ?>

        <?php if ($stale): ?>
            <div class="alert alert--danger">
                Воркер не отчитывается дольше <?= (int) round(UpdateState::STALE_AFTER / 60) ?> минут — процесс
                обновления оборвался.
                <?php if ($state['files_touched']): ?>
                    Замена файлов уже шла, поэтому <strong>сайт остаётся закрытым</strong>: собранный наполовину,
                    он отдавал бы посетителям ошибку, а закрытый — понятную страницу. Проверьте работу сайта
                    (панель доступна и при закрытом сайте) и снимите режим обслуживания кнопкой ниже.
                    Если сайт сломан — разверните резервную копию, снятую перед обновлением.
                <?php else: ?>
                    Замена файлов не начиналась, файлы целы — сайт открыт, обновление можно повторить.
                <?php endif; ?>
            </div>
            <form method="post" action="/admin/update/reset">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn--danger"><?= AdminUi::icon('rotate') ?>Сбросить состояние и открыть сайт</button>
            </form>
        <?php endif; ?>

        <?php if ($state['log'] !== []): ?>
            <table class="data-table">
                <thead><tr><th class="u-inline-611bf920db">Время</th><th>Шаг</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($state['log']) as $line): ?>
                        <tr>
                            <td><?= htmlspecialchars($moment($line['at']), ENT_QUOTES) ?></td>
                            <td>
                                <?php if ($line['level'] === 'fail'): ?>
                                    <span class="badge badge--danger">Отказ</span>
                                <?php endif; ?>
                                <?= htmlspecialchars($line['text'], ENT_QUOTES) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php // --- Заказ ------------------------------------------------------------ ?>
<?php if (($check['ok'] ?? false) && ($check['available'] ?? false) && ($check['installable'] ?? false)): ?>
    <div class="settings-card">
        <h2>Обновить до <?= htmlspecialchars((string) ($check['latest'] ?? ''), ENT_QUOTES) ?></h2>
        <?php if ($busy): ?>
            <div class="alert alert--info">Обновление уже идёт — дождитесь окончания.</div>
        <?php else: ?>
            <form method="post" action="/admin/update/request">
                <?= \App\Core\Csrf::field() ?>
                <div class="form-field">
                    <label class="form-label" for="update-confirm">
                        Введите <code><?= htmlspecialchars($confirmCode, ENT_QUOTES) ?></code> для подтверждения
                    </label>
                    <input type="text" id="update-confirm" name="confirm" class="form-control"
                           autocomplete="off" placeholder="<?= htmlspecialchars($confirmCode, ENT_QUOTES) ?>">
                </div>
                <button type="submit" class="btn btn--primary" <?= $worker['alive'] ? '' : 'disabled' ?>>
                    <?= AdminUi::icon('download') ?>Обновить систему
                </button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($busy): ?>
    <?php // Пока обновление идёт, страница освежается сама: ход пишет другой
          // процесс, и без этого владелец смотрел бы на застывший экран. ?>
    <script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
        setTimeout(function () { window.location.reload(); }, 10000);
    </script>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
