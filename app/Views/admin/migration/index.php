<?php
/**
 * Раздел «Переезд»: пакет сайта на старом сервере, установка на новом.
 *
 * Страница заказывает и показывает; работу делает
 * app/Console/migration_worker.php (см. Admin\MigrationController).
 *
 * @var array{task:string, status:string, requested_by:string, requested_at:int,
 *     started_at:int, finished_at:int, heartbeat:int, archive:string,
 *     options:array{from_url:string, to_url:string, plain_backup:bool},
 *     result:string, error:string, log:list<array{at:int,level:string,text:string}>} $state
 * @var bool $stale
 * @var bool $busy
 * @var array{last:?int, age:?int, alive:bool} $worker
 * @var list<array{name:string, size:int, mtime:int, checksum:bool, encrypted:bool,
 *     manifest:?array<string,mixed>, key_ok:bool, key_message:string, error:string}> $incoming
 * @var list<array{name:string, size:int, mtime:int}> $outgoing
 * @var string $incomingDir
 * @var string $outgoingDir
 * @var bool $encryption
 * @var string $appUrl
 * @var string $confirmCode
 * @var int $passwordMin
 * @var string $cronLine
 */

use App\Core\AdminUi;
use App\Core\SiteMigrationState;

$pageTitle = 'Переезд на другой хостинг';
$activeNav = 'migration';
require __DIR__ . '/../layout/header.php';

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
$moment = static fn (int $ts): string => $ts > 0 ? date('d.m.Y H:i:s', $ts) : '—';
$mb = static fn (int $bytes): string => number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
$ago = static function (?int $seconds): string {
    if ($seconds === null) {
        return 'ни разу';
    }

    return $seconds < 60 ? ($seconds . ' с назад') : (int) round($seconds / 60) . ' мин назад';
};
$canQueue = $worker['alive'] && !$busy;
?>

<div class="settings-card">
    <p class="settings-card__subtitle">
        Переезд — два шага. На <strong>старом</strong> сервере снимается пакет: база, загрузки и манифест
        (адрес сайта, версия, отпечаток ключа шифрования), зашифрованные паролем. На <strong>новом</strong>
        сервере пакет кладётся файловым менеджером хостинга в папку ниже и устанавливается отсюда же.
    </p>
    <p class="settings-card__subtitle">
        Перед установкой на новый сервер перенесите в его <code>config/config.php</code> (или окружение)
        ключ <code>APP_ENCRYPTION_KEY</code> со старого: архив его не несёт намеренно, а без него не
        расшифруются коды входа и токены интеграций. Установка сверит отпечаток ключа до замены базы.
    </p>
    <p class="settings-card__subtitle">
        Работу выполняет фоновый воркер: снятие медиатеки и замена базы длиннее таймаута веб-запроса.
        После переезда удалите пакет на обоих серверах — в нём вся база сайта.
    </p>
</div>

<?php // --- Воркер ---------------------------------------------------------- ?>
<div class="settings-card">
    <h2>Фоновый воркер</h2>
    <?php if ($worker['alive']): ?>
        <p class="settings-card__subtitle">
            <span class="badge badge--success">Отвечает</span>
            последний запуск: <?= $e($moment((int) $worker['last'])) ?> (<?= $e($ago($worker['age'])) ?>).
        </p>
    <?php else: ?>
        <div class="alert alert--warning">
            Воркер не отвечает (<?= $e($ago($worker['age'])) ?>), поэтому заказать переезд нельзя: задачу некому
            выполнить. Заведите в панели хостинга задание cron — раз в минуту; после переезда его можно снять:
        </div>
        <pre><code><?= $e($cronLine) ?></code></pre>
        <p class="settings-card__subtitle">
            На Hostinger: hPanel → «Расширенные» → «Cron Jobs» → «Пользовательский», команда — строка выше
            без пяти звёздочек в начале, во всех полях времени — <code>*</code> (каждую минуту). Через минуту
            после сохранения обновите эту страницу: статус сменится на «Отвечает».
        </p>
    <?php endif; ?>
</div>

<?php // --- Ход задачи ------------------------------------------------------- ?>
<?php if ($state['status'] !== SiteMigrationState::STATUS_IDLE): ?>
    <div class="settings-card">
        <h2><?= $state['task'] === SiteMigrationState::TASK_EXPORT ? 'Снятие пакета' : 'Установка пакета' ?></h2>
        <p class="settings-card__subtitle">
            <?php if ($stale): ?>
                <span class="badge badge--danger">Нет отклика</span>
            <?php elseif ($state['status'] === SiteMigrationState::STATUS_RUNNING): ?>
                <span class="badge badge--accent">Выполняется</span>
            <?php elseif ($state['status'] === SiteMigrationState::STATUS_QUEUED): ?>
                <span class="badge badge--accent">В очереди</span>
            <?php elseif ($state['status'] === SiteMigrationState::STATUS_DONE): ?>
                <span class="badge badge--success">Завершено</span>
            <?php else: ?>
                <span class="badge badge--danger">Остановлено</span>
            <?php endif; ?>
            <?php if ($state['archive'] !== ''): ?>
                — <?= $e($state['archive']) ?>,
            <?php endif; ?>
            заказал <?= $e($state['requested_by'] !== '' ? $state['requested_by'] : '—') ?>
            <?= $e($moment($state['requested_at'])) ?>.
        </p>

        <?php if ($state['error'] !== ''): ?>
            <div class="alert alert--danger"><?= $e($state['error']) ?></div>
        <?php endif; ?>

        <?php if ($stale): ?>
            <div class="alert alert--danger">
                Воркер не отчитывается дольше <?= (int) round(SiteMigrationState::STALE_AFTER / 60) ?> минут.
                Если он жив и просто долго распаковывает медиатеку — подождите; если cron остановлен —
                сбросьте задачу и проверьте сайт.
            </div>
            <form method="post" action="/admin/migration/reset">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn--danger"><?= AdminUi::icon('rotate') ?>Сбросить задачу</button>
            </form>
        <?php endif; ?>

        <?php if ($state['log'] !== []): ?>
            <table class="data-table">
                <thead><tr><th class="u-inline-611bf920db">Время</th><th>Шаг</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($state['log']) as $line): ?>
                        <tr>
                            <td><?= $e($moment($line['at'])) ?></td>
                            <td>
                                <?php if ($line['level'] === 'fail'): ?>
                                    <span class="badge badge--danger">Отказ</span>
                                <?php endif; ?>
                                <?= $e($line['text']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php // --- Старый сервер: пакет -------------------------------------------- ?>
<div class="settings-card">
    <h2>Старый сервер: снять пакет</h2>
    <p class="settings-card__subtitle">
        Пакет ложится в <code><?= $e($outgoingDir) ?></code>. Надёжнее всего перенести его на новый сервер
        напрямую (SFTP или файловый менеджер) вместе с файлом <code>.sha256</code>; скачать можно и отсюда.
        Пароль не хранится нигде — запишите его: без него пакет не открыть.
    </p>
    <?php if (!$encryption): ?>
        <div class="alert alert--warning">
            Библиотека libzip этого сервера собрана без AES-256 — зашифрованный пакет здесь не снять.
            Снимите его из консоли: <code>php scripts/site_migrate.php export</code>.
        </div>
    <?php elseif ($busy): ?>
        <div class="alert alert--info">Задача переезда уже идёт — дождитесь окончания.</div>
    <?php else: ?>
        <form method="post" action="/admin/migration/export" autocomplete="off">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-grid-12">
                <div class="form-field col-6">
                    <label for="migration-password">Пароль архива (не короче <?= $passwordMin ?> знаков)</label>
                    <input type="password" id="migration-password" name="password" class="form-control"
                           minlength="<?= $passwordMin ?>" autocomplete="new-password" required>
                </div>
                <div class="form-field col-6">
                    <label for="migration-password-repeat">Пароль ещё раз</label>
                    <input type="password" id="migration-password-repeat" name="password_repeat" class="form-control"
                           minlength="<?= $passwordMin ?>" autocomplete="new-password" required>
                </div>
            </div>
            <button type="submit" class="btn btn--primary" <?= $canQueue ? '' : 'disabled' ?>>
                <?= AdminUi::icon('package') ?>Снять пакет переезда
            </button>
            <?php if (!$worker['alive']): ?>
                <p class="settings-card__subtitle">Кнопка станет активной, когда заработает фоновый воркер — см. «Фоновый воркер» выше.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($outgoing !== []): ?>
        <table class="data-table">
            <thead><tr><th>Пакет</th><th>Размер</th><th>Снят</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($outgoing as $file): ?>
                    <tr>
                        <td><code><?= $e($file['name']) ?></code></td>
                        <td><?= $e($mb($file['size'])) ?></td>
                        <td><?= $e($moment($file['mtime'])) ?></td>
                        <td>
                            <a class="btn btn--secondary btn--small" href="/admin/migration/download?file=<?= rawurlencode($file['name']) ?>"><?= AdminUi::icon('download') ?>Архив</a>
                            <a class="btn btn--secondary btn--small" href="/admin/migration/download?file=<?= rawurlencode($file['name'] . '.sha256') ?>">.sha256</a>
                            <form method="post" action="/admin/migration/delete" data-confirm="Удалить пакет <?= $e($file['name']) ?>?">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="where" value="outgoing">
                                <input type="hidden" name="archive" value="<?= $e($file['name']) ?>">
                                <button type="submit" class="btn btn--danger btn--small"><?= AdminUi::icon('trash') ?>Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php // --- Новый сервер: установка ------------------------------------------ ?>
<div class="settings-card">
    <h2>Новый сервер: установить пакет</h2>
    <p class="settings-card__subtitle">
        Положите архив и его <code>.sha256</code> в <code><?= $e($incomingDir) ?></code> файловым менеджером
        хостинга (форма загрузки упирается в лимит размера, а архив с медиатекой его превышает) и обновите
        страницу. Установка <strong>заменит базу и загрузки этого сервера целиком</strong>; перед заменой
        снимается страховочная копия, при ошибке всё откатывается.
    </p>

    <?php if ($incoming === []): ?>
        <div class="alert alert--info">В папке нет архивов.</div>
    <?php endif; ?>

    <?php foreach ($incoming as $file): ?>
        <?php $manifest = $file['manifest']; ?>
        <table class="data-table">
            <tbody>
                <tr><th class="u-inline-7006b2a9a3">Архив</th><td><code><?= $e($file['name']) ?></code>, <?= $e($mb($file['size'])) ?></td></tr>
                <tr>
                    <th>Проверки</th>
                    <td>
                        <?php if ($file['error'] !== ''): ?>
                            <span class="badge badge--danger">Не читается</span> <?= $e($file['error']) ?>
                        <?php else: ?>
                            <div>
                                <?= $file['checksum'] ? '<span class="badge badge--success">.sha256 рядом</span>' : '<span class="badge badge--danger">Нет .sha256</span>' ?>
                                <?= $file['encrypted'] ? '<span class="badge badge--success">Зашифрован</span>' : '<span class="badge badge--warning">Без пароля</span>' ?>
                            </div>
                            <div>
                                <?= $file['key_ok'] ? '<span class="badge badge--success">Ключ</span>' : '<span class="badge badge--danger">Ключ</span>' ?>
                                <?= $e($file['key_message']) ?>
                            </div>
                            <?php if ($manifest !== null): ?>
                                <div>
                                    Старый адрес: <code><?= $e((string) ($manifest['app_url'] ?? '') ?: '—') ?></code>,
                                    версия <?= $e((string) ($manifest['release'] ?? '—')) ?>,
                                    снят <?= $e((string) ($manifest['created_at'] ?? '—')) ?>.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if ($file['error'] === '' && !$busy): ?>
            <?php $slug = substr(md5($file['name']), 0, 8); ?>
            <form method="post" action="/admin/migration/import" autocomplete="off">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="archive" value="<?= $e($file['name']) ?>">
                <div class="form-grid-12">
                    <?php if ($file['encrypted']): ?>
                        <div class="form-field col-6">
                            <label for="mi-pass-<?= $slug ?>">Пароль архива</label>
                            <input type="password" id="mi-pass-<?= $slug ?>" name="password" class="form-control"
                                   autocomplete="off" required>
                        </div>
                    <?php endif; ?>
                    <div class="form-field col-6">
                        <label for="mi-from-<?= $slug ?>">Старый адрес сайта</label>
                        <input type="url" id="mi-from-<?= $slug ?>" name="from_url" class="form-control"
                               value="<?= $e((string) ($manifest['app_url'] ?? '')) ?>" placeholder="https://old.uz">
                    </div>
                    <div class="form-field col-6">
                        <label for="mi-to-<?= $slug ?>">Новый адрес сайта</label>
                        <input type="url" id="mi-to-<?= $slug ?>" name="to_url" class="form-control"
                               value="<?= $e($appUrl) ?>" placeholder="https://new.uz">
                    </div>
                    <?php if ($manifest === null): ?>
                        <div class="form-field form-field--checkbox col-12">
                            <input type="checkbox" id="mi-plain-<?= $slug ?>" name="plain_backup" value="1">
                            <label for="mi-plain-<?= $slug ?>">Это обычная резервная копия; ключ шифрования перенесён вручную</label>
                        </div>
                    <?php endif; ?>
                    <div class="form-field col-6">
                        <label for="mi-confirm-<?= $slug ?>">Введите <code><?= $e($confirmCode) ?></code> для подтверждения</label>
                        <input type="text" id="mi-confirm-<?= $slug ?>" name="confirm" class="form-control"
                               autocomplete="off" placeholder="<?= $e($confirmCode) ?>" required>
                    </div>
                </div>
                <p class="settings-card__subtitle">
                    Абсолютные ссылки старого адреса в материалах заменятся новым. После замены базы панель
                    попросит войти заново — учётной записью <strong>старого</strong> сайта.
                </p>
                <button type="submit" class="btn btn--danger" <?= $canQueue ? '' : 'disabled' ?>>
                    <?= AdminUi::icon('upload') ?>Установить пакет
                </button>
                <?php if (!$worker['alive']): ?>
                    <p class="settings-card__subtitle">Кнопка станет активной, когда заработает фоновый воркер — см. «Фоновый воркер» выше.</p>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <form method="post" action="/admin/migration/delete" data-confirm="Удалить архив <?= $e($file['name']) ?>?">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="where" value="incoming">
            <input type="hidden" name="archive" value="<?= $e($file['name']) ?>">
            <button type="submit" class="btn btn--secondary btn--small"><?= AdminUi::icon('trash') ?>Удалить архив</button>
        </form>
    <?php endforeach; ?>
</div>

<?php if ($busy): ?>
    <?php // Ход пишет другой процесс — без обновления экран застыл бы. ?>
    <script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
        setTimeout(function () { window.location.reload(); }, 10000);
    </script>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
