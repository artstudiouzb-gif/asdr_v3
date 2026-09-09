<?php

use App\Core\AdminUi;
use App\Models\AuditLog;

/** @var array<int, array<string, mixed>> $authEvents */
/** @var array{total:int,successful:int,failed:int,locked:int,delivery_failed:int} $authSummary */
/** @var array<int, string> $technicalEvents */
/** @var array<string, mixed>|null $currentUser */
/** @var int $activeSessions */
/** @var bool $botLinked */
/** @var bool $gatewayLinked */
/** @var array{enabled:bool,path:string,url:string,ttl:int,allowed_cidrs:list<string>,environment_managed:bool,writable:bool} $adminEntry */

$pageTitle = 'Безопасность';
$activeNav = 'security';
require __DIR__ . '/../layout/header.php';

$twoFactorActive = $botLinked || $gatewayLinked;
$channels = [];
if ($botLinked) {
    $channels[] = 'Telegram-бот';
}
if ($gatewayLinked) {
    $channels[] = 'Telegram Gateway';
}
$riskEvents = (int) $authSummary['failed'] + (int) $authSummary['locked'];
$entryEnabled = !empty($adminEntry['enabled']);
$entryManaged = !empty($adminEntry['environment_managed']);
$entryWritable = !empty($adminEntry['writable']);
?>

<div class="u-inline-f94566b02a">
    <a class="btn btn--small btn--primary" href="/admin/security">Центр безопасности</a>
    <a class="btn btn--small" href="/admin/audit">Действия администраторов</a>
    <a class="btn btn--small" href="/admin/audit/errors">Ошибки сайта</a>
</div>

<p class="admin-subtitle">Состояние защиты входа, активные сессии и понятная история событий аутентификации.</p>

<div class="admin-grid-auto admin-status-grid">
    <div class="admin-status-card">
        <h3 class="admin-status-card__label">Защита входа</h3>
        <div class="admin-status-card__value">
            <?php if ($twoFactorActive): ?>
                <span class="admin-status--success"><?= AdminUi::icon('lock', 20) ?> 2FA включена</span>
            <?php else: ?>
                <span class="admin-status--warning"><?= AdminUi::icon('warning', 20) ?> Требуется настройка</span>
            <?php endif; ?>
        </div>
        <p class="form-hint u-inline-1da9facb4d">
            <?= $twoFactorActive
                ? 'Канал: ' . htmlspecialchars(implode(' + ', $channels), ENT_QUOTES)
                : 'Нет рабочего канала доставки одноразового кода.' ?>
        </p>
    </div>

    <div class="admin-status-card">
        <h3 class="admin-status-card__label">Активные сессии</h3>
        <div class="admin-status-card__value"><?= (int) $activeSessions ?></div>
        <p class="form-hint u-inline-1da9facb4d">Устройства текущего администратора</p>
    </div>

    <div class="admin-status-card">
        <h3 class="admin-status-card__label">Неудачные попытки за 24 часа</h3>
        <div class="admin-status-card__value">
            <span class="<?= $riskEvents > 0 ? 'admin-status--warning' : 'admin-status--success' ?>">
                <?= (int) $riskEvents ?>
            </span>
        </div>
        <p class="form-hint u-inline-1da9facb4d">
            Ошибок кода: <?= (int) $authSummary['failed'] ?> · блокировок: <?= (int) $authSummary['locked'] ?>
        </p>
    </div>

    <div class="admin-status-card">
        <h3 class="admin-status-card__label">Последний успешный вход</h3>
        <div class="admin-status-card__value">
            <?= !empty($currentUser['last_login_at'])
                ? htmlspecialchars((string) $currentUser['last_login_at'], ENT_QUOTES)
                : 'Нет данных' ?>
        </div>
        <p class="form-hint u-inline-1da9facb4d">
            <?= htmlspecialchars((string) ($currentUser['username'] ?? '—'), ENT_QUOTES) ?>
            · IP <?= htmlspecialchars((string) ($_SERVER['REMOTE_ADDR'] ?? '—'), ENT_QUOTES) ?>
        </p>
    </div>
</div>

<div class="form-card u-inline-1e1a9b09bf">
    <div class="admin-card-header">
        <div class="admin-card-header__title">
            <span class="admin-card-header__icon"><?= AdminUi::icon('shield', 20) ?></span>
            <h3>Управление защитой</h3>
        </div>
    </div>
    <p class="form-hint">В профиле можно подключить канал кодов входа, сменить пароль, проверить устройства и завершить другие сессии.</p>
    <div class="form-actions">
        <a href="/admin/profile" class="btn btn--primary">Профиль и сессии</a>
        <a href="/admin/telegram" class="btn">Настройки Telegram</a>
    </div>
</div>

<div class="form-card u-inline-1e1a9b09bf" id="admin-entry">
    <div class="admin-card-header">
        <div class="admin-card-header__title">
            <span class="admin-card-header__icon"><?= AdminUi::icon('key', 20) ?></span>
            <h3>Скрытый адрес входа</h3>
        </div>
        <span class="status-badge status-badge--<?= $entryEnabled ? 'success' : 'warning' ?>">
            <?= $entryEnabled ? 'Включён' : 'Не настроен' ?>
        </span>
    </div>

    <p class="form-hint">
        После включения прямые запросы к <code>/admin</code>, <code>/admin/login</code>
        и восстановлению пароля будут возвращать обычный 404. Через один общий
        секретный адрес входят и администраторы, и редакторы; права определяются
        личной учётной записью после авторизации.
    </p>

    <?php if ($entryEnabled): ?>
        <div class="admin-code-panel admin-mt-24">
            <strong>Текущий адрес:</strong>
            <span><?= htmlspecialchars((string) $adminEntry['url'], ENT_QUOTES) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($entryManaged): ?>
        <div class="alert alert--info admin-mt-24">
            Адрес управляется переменной окружения <code>ADMIN_ENTRY_PATH</code>.
            Измените её на сервере; интерфейс CMS не перезаписывает deployment-настройки.
        </div>
    <?php elseif (!$entryWritable): ?>
        <div class="alert alert--error admin-mt-24">
            Каталог <code>storage/</code> недоступен для записи. Настройка не может быть сохранена.
        </div>
    <?php else: ?>
        <form method="post" action="/admin/security/admin-entry" class="form-grid admin-mt-24">
            <?= \App\Core\Csrf::field() ?>

            <div class="form-field">
                <label for="admin_entry_path">Секретный путь</label>
                <input id="admin_entry_path" name="admin_entry_path" type="text"
                       maxlength="97" autocomplete="off" spellcheck="false"
                       value="<?= htmlspecialchars((string) $adminEntry['path'], ENT_QUOTES) ?>"
                       placeholder="/control-случайное-значение">
                <span class="form-hint">Один сегмент длиной 16–96 символов: буквы, цифры, дефис и подчёркивание.</span>
            </div>

            <div class="form-field">
                <label for="admin_entry_ttl">Срок временного пропуска</label>
                <select id="admin_entry_ttl" name="admin_entry_ttl">
                    <?php foreach ([900 => '15 минут', 1800 => '30 минут', 3600 => '60 минут'] as $ttl => $label): ?>
                        <option value="<?= $ttl ?>" <?= (int) $adminEntry['ttl'] === $ttl ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label for="admin_entry_allowed_cidrs">Ограничение по IP/CIDR</label>
                <textarea id="admin_entry_allowed_cidrs" name="admin_entry_allowed_cidrs" rows="3"
                          placeholder="Например: 203.0.113.10, 2001:db8::/48"><?= htmlspecialchars(implode("\n", $adminEntry['allowed_cidrs']), ENT_QUOTES) ?></textarea>
                <span class="form-hint">Пусто — доступ с любого IP. Неверный список может заблокировать вход.</span>
            </div>

            <label class="form-field form-field--checkbox">
                <input type="checkbox" name="confirm_access_change" value="1" required>
                Я сохранил новый адрес в безопасном месте и понимаю, что старый адрес перестанет работать.
            </label>

            <div class="form-actions">
                <button type="submit" name="action" value="save" class="btn btn--primary">Сохранить</button>
                <button type="submit" name="action" value="regenerate" class="btn">Создать новый адрес</button>
                <?php if ($entryEnabled): ?>
                    <button type="submit" name="action" value="disable" class="btn btn--danger">Отключить скрытый вход</button>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="form-card u-inline-1e1a9b09bf">
    <div class="admin-card-header">
        <div class="admin-card-header__title">
            <span class="admin-card-header__icon"><?= AdminUi::icon('list', 20) ?></span>
            <h3>Последние события входа</h3>
        </div>
        <a class="btn btn--small" href="/admin/audit?method=AUTH">Открыть весь журнал</a>
    </div>

    <p class="form-hint">
        За 24 часа: успешных подтверждений — <strong><?= (int) $authSummary['successful'] ?></strong>,
        неудачных — <strong><?= (int) $authSummary['failed'] ?></strong>,
        ошибок доставки кода — <strong><?= (int) $authSummary['delivery_failed'] ?></strong>.
    </p>

    <?php if ($authEvents === []): ?>
        <p class="admin-empty">Событий входа пока нет.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th>Когда</th><th>Событие</th><th>Пользователь</th><th>IP</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($authEvents as $event): ?>
                        <?php $meta = AuditLog::authEventMeta((string) ($event['path'] ?? '')); ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($event['created_at'] ?? ''), ENT_QUOTES) ?></td>
                            <td>
                                <span class="status-badge status-badge--<?= htmlspecialchars($meta['tone'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($meta['label'], ENT_QUOTES) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string) ($event['username'] ?: 'Не определён'), ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars((string) ($event['ip'] ?: '—'), ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="form-card">
    <details>
        <summary><strong>Технический security.log</strong></summary>
        <p class="form-hint">Служебные сообщения защиты. Для ежедневной работы используйте таблицу событий выше.</p>
        <div class="admin-code-panel">
            <?php if ($technicalEvents === []): ?>
                <span class="admin-code-panel__empty">Технических сообщений пока нет.</span>
            <?php else: ?>
                <?php foreach ($technicalEvents as $event): ?>
                    <div class="admin-code-panel__row"><?= htmlspecialchars($event, ENT_QUOTES) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </details>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
