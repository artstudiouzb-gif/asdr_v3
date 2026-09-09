<?php

use App\Core\AdminUi;
use App\Core\Csrf;

/** @var list<array<string,mixed>> $items */
/** @var int $unreadCount */
/** @var int $pendingAckCount */
/** @var array<string,mixed> $preferences */
/** @var array{pending:int,sent:int,failed:int} $deliveries */
/** @var bool $isSuperAdmin */

$pageTitle = 'Уведомления';
$activeNav = 'notifications';
require __DIR__ . '/../layout/header.php';

$severityLabels = [
    'info' => 'Информация',
    'success' => 'Успешно',
    'warning' => 'Внимание',
    'error' => 'Ошибка',
    'critical' => 'Критическое',
    'security' => 'Безопасность',
];
$categoryLabels = [
    'system' => 'Система',
    'security' => 'Безопасность',
    'content' => 'Контент',
    'forms' => 'Заявки',
    'users' => 'Пользователи',
    'integration' => 'Интеграции',
];
?>

<div class="notification-page-head">
    <div>
        <p class="admin-subtitle">Персональная лента рабочих, системных и security-событий.</p>
    </div>
    <?php if ($unreadCount > 0): ?>
        <form method="post" action="/admin/notifications/read-all">
            <?= Csrf::field() ?>
            <button type="submit" class="btn">
                <?= AdminUi::icon('checks', 17) ?>
                Отметить все прочитанными
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="notification-summary-grid">
    <div class="notification-summary-card">
        <span class="notification-summary-card__icon"><?= AdminUi::icon('bell', 22) ?></span>
        <div>
            <strong><?= (int) $unreadCount ?></strong>
            <span>непрочитанных</span>
        </div>
    </div>
    <div class="notification-summary-card<?= $pendingAckCount > 0 ? ' notification-summary-card--attention' : '' ?>">
        <span class="notification-summary-card__icon"><?= AdminUi::icon('shield-check', 22) ?></span>
        <div>
            <strong><?= (int) $pendingAckCount ?></strong>
            <span>требуют подтверждения</span>
        </div>
    </div>
    <?php if ($isSuperAdmin): ?>
        <div class="notification-summary-card">
            <span class="notification-summary-card__icon"><?= AdminUi::icon('send', 22) ?></span>
            <div>
                <strong><?= (int) $deliveries['pending'] ?></strong>
                <span>в очереди доставки</span>
            </div>
        </div>
        <div class="notification-summary-card<?= $deliveries['failed'] > 0 ? ' notification-summary-card--danger' : '' ?>">
            <span class="notification-summary-card__icon"><?= AdminUi::icon('alert-triangle', 22) ?></span>
            <div>
                <strong><?= (int) $deliveries['failed'] ?></strong>
                <span>ошибок доставки</span>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="notification-layout">
    <section class="notification-feed" aria-label="Лента уведомлений">
        <?php if ($items === []): ?>
            <div class="notification-empty">
                <?= AdminUi::icon('bell-off', 36) ?>
                <h2>Новых событий нет</h2>
                <p>Здесь появятся заявки, редакционные задачи, результаты публикации и важные события безопасности.</p>
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                $severity = (string) ($item['severity'] ?? 'info');
                $category = (string) ($item['category'] ?? 'system');
                $isUnread = empty($item['read_at']);
                $requiresAck = !empty($item['requires_ack']);
                $acknowledged = !empty($item['acknowledged_at']);
                ?>
                <article class="notification-card notification-card--<?= htmlspecialchars($severity, ENT_QUOTES) ?><?= $isUnread ? ' is-unread' : '' ?>">
                    <div class="notification-card__marker" aria-hidden="true"></div>
                    <div class="notification-card__body">
                        <div class="notification-card__meta">
                            <span class="notification-chip notification-chip--<?= htmlspecialchars($severity, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($severityLabels[$severity] ?? $severityLabels['info'], ENT_QUOTES) ?>
                            </span>
                            <span><?= htmlspecialchars($categoryLabels[$category] ?? $categoryLabels['system'], ENT_QUOTES) ?></span>
                            <time datetime="<?= htmlspecialchars((string) $item['created_at'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars((string) $item['created_at'], ENT_QUOTES) ?>
                            </time>
                            <?php if ($isUnread): ?><span class="notification-new-dot">Новое</span><?php endif; ?>
                        </div>
                        <h2><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></h2>
                        <p><?= nl2br(htmlspecialchars((string) $item['message'], ENT_QUOTES)) ?></p>

                        <div class="notification-card__actions">
                            <?php if (!empty($item['action_url'])): ?>
                                <a class="btn btn--small btn--primary" href="<?= htmlspecialchars((string) $item['action_url'], ENT_QUOTES) ?>">
                                    Открыть
                                    <?= AdminUi::icon('arrow-right', 15) ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($requiresAck && !$acknowledged): ?>
                                <form method="post" action="/admin/notifications/<?= (int) $item['id'] ?>/acknowledge">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn--small">
                                        <?= AdminUi::icon('shield-check', 15) ?>
                                        Подтвердить ознакомление
                                    </button>
                                </form>
                            <?php elseif ($isUnread): ?>
                                <form method="post" action="/admin/notifications/<?= (int) $item['id'] ?>/read">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn--small">Прочитано</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!$requiresAck || $acknowledged): ?>
                                <form method="post" action="/admin/notifications/<?= (int) $item['id'] ?>/dismiss">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn--small btn--secondary" aria-label="Убрать уведомление">
                                        <?= AdminUi::icon('x', 15) ?>
                                        Убрать
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <aside class="notification-settings">
        <div class="form-card">
            <div class="admin-card-header">
                <div class="admin-card-header__title">
                    <span class="admin-card-header__icon"><?= AdminUi::icon('adjustments', 20) ?></span>
                    <h3>Каналы доставки</h3>
                </div>
            </div>

            <form method="post" action="/admin/notifications/preferences" class="form-grid">
                <?= Csrf::field() ?>
                <label class="notification-channel-option">
                    <input type="checkbox" name="email_enabled" value="1" <?= !empty($preferences['email_enabled']) ? 'checked' : '' ?>>
                    <span>
                        <strong>E-mail</strong>
                        <small>На адрес текущей учётной записи</small>
                    </span>
                </label>
                <label class="notification-channel-option">
                    <input type="checkbox" name="telegram_enabled" value="1" <?= !empty($preferences['telegram_enabled']) ? 'checked' : '' ?>>
                    <span>
                        <strong>Telegram</strong>
                        <small>Через привязанный личный чат с ботом</small>
                    </span>
                </label>

                <div class="form-field">
                    <label for="notification_minimum_severity">Отправлять наружу начиная с уровня</label>
                    <select id="notification_minimum_severity" name="minimum_severity">
                        <?php foreach (['info' => 'Все события', 'warning' => 'Внимание и выше', 'error' => 'Ошибки и выше', 'security' => 'Security и критические', 'critical' => 'Только критические'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= (string) $preferences['minimum_severity'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="notification-quiet-grid">
                    <div class="form-field">
                        <label for="notification_quiet_start">Тихие часы с</label>
                        <input id="notification_quiet_start" type="time" name="quiet_start" value="<?= htmlspecialchars(substr((string) ($preferences['quiet_start'] ?? ''), 0, 5), ENT_QUOTES) ?>">
                    </div>
                    <div class="form-field">
                        <label for="notification_quiet_end">до</label>
                        <input id="notification_quiet_end" type="time" name="quiet_end" value="<?= htmlspecialchars(substr((string) ($preferences['quiet_end'] ?? ''), 0, 5), ENT_QUOTES) ?>">
                    </div>
                </div>
                <p class="form-hint">Критические и security-события доставляются даже в тихие часы.</p>

                <input type="hidden" name="digest_mode" value="none">
                <button type="submit" class="btn btn--primary">Сохранить настройки</button>
            </form>
        </div>

        <div class="notification-privacy-note">
            <?= AdminUi::icon('lock', 18) ?>
            <p><strong>Безопасная доставка.</strong> Во внешние каналы передаётся только краткое описание. Токены, cookie, секретный адрес входа и полные персональные данные не отправляются.</p>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
