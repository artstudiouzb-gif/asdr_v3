<?php

use App\Core\AdminUi;
use App\Core\Csrf;

$pageTitle = 'Подписчики дайджеста';
$activeNav = 'subscribers';
$pageActions = '<form method="post" action="/admin/subscribers/send-digest" data-confirm="Сформировать дайджест из новостей за последние 7 дней?">'
    . Csrf::field()
    . '<button type="submit" class="btn btn--primary">' . AdminUi::icon('send') . 'Сформировать дайджест</button>'
    . '</form>';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array $filters */
/** @var array $summary */
/** @var array $queue */
/** @var array $languages */
$status = (string) ($filters['status'] ?? '');
$lang = (string) ($filters['lang'] ?? '');
$query = (string) ($filters['q'] ?? '');
$filterParams = ['q' => $query, 'status' => $status, 'lang' => $lang];
?>

<div class="submission-summary">
    <a href="/admin/subscribers?status=active" class="submission-summary__card submission-summary__card--new">
        <span>Активные</span>
        <strong><?= (int) ($summary['active'] ?? 0) ?></strong>
    </a>
    <a href="/admin/subscribers?status=unsubscribed" class="submission-summary__card">
        <span>Отписались</span>
        <strong><?= (int) ($summary['unsubscribed'] ?? 0) ?></strong>
    </a>
    <a href="/admin/subscribers" class="submission-summary__card">
        <span>Всего записей</span>
        <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
    </a>
</div>

<div class="form-card">
    <h2 class="form-card__title">Как работает дайджест</h2>
    <p class="form-hint">Формы на сайте сохраняют email, язык, источник и согласие. Еженедельный <code>digest_worker</code> собирает опубликованные за 7 дней новости на языке подписчика и ставит персональные письма в очередь. <code>mail_worker</code> отправляет очередь; при отписке ещё не отправленные письма отменяются.</p>
    <p class="form-hint">
        Очередь: <strong><?= (int) ($queue['pending'] ?? 0) ?></strong> ожидает,
        <strong><?= (int) ($queue['sent'] ?? 0) ?></strong> отправлено,
        <strong><?= (int) ($queue['failed'] ?? 0) ?></strong> с ошибкой,
        <strong><?= (int) ($queue['cancelled'] ?? 0) ?></strong> отменено.
        <?php if (!empty($queue['last_queued'])): ?>
            Последнее формирование: <strong><?= htmlspecialchars((string) $queue['last_queued'], ENT_QUOTES) ?></strong>.
        <?php endif; ?>
    </p>
    <p class="form-hint">Кнопка «Сформировать дайджест» безопасна: повторный запуск в ту же ISO-неделю не создаёт дубликаты.</p>
</div>

<form method="get" action="/admin/subscribers" class="admin-filter-bar form-card">
    <div class="form-field admin-filter-bar__search">
        <label for="subscriber-q">Email</label>
        <input id="subscriber-q" type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" placeholder="Найти адрес…">
    </div>
    <div class="form-field">
        <label for="subscriber-status">Статус</label>
        <select id="subscriber-status" name="status">
            <option value="">Все</option>
            <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Активные</option>
            <option value="unsubscribed"<?= $status === 'unsubscribed' ? ' selected' : '' ?>>Отписались</option>
        </select>
    </div>
    <div class="form-field">
        <label for="subscriber-lang">Язык</label>
        <select id="subscriber-lang" name="lang">
            <option value="">Все</option>
            <?php foreach ($languages as $language): ?>
                <?php $code = (string) ($language['code'] ?? ''); ?>
                <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"<?= $lang === $code ? ' selected' : '' ?>>
                    <?= htmlspecialchars((string) ($language['name'] ?? $code), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn--primary"><?= AdminUi::icon('search') ?>Найти</button>
        <a href="/admin/subscribers" class="btn">Сбросить</a>
    </div>
</form>

<?php if (empty($items)): ?>
    <div class="form-card"><p class="form-hint">По выбранным условиям подписчиков нет.</p></div>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Email</th><th>Статус</th><th>Язык / источник</th><th>Согласие</th><th>Дайджест</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) $item['email'], ENT_QUOTES) ?></strong>
                        <div class="form-hint"><?= htmlspecialchars((string) $item['created_at'], ENT_QUOTES) ?></div>
                    </td>
                    <td>
                        <?php if (($item['status'] ?? '') === 'active'): ?>
                            <span class="status-badge status-badge--published">Активен</span>
                        <?php else: ?>
                            <span class="status-badge status-badge--draft">Отписан</span>
                            <?php if (!empty($item['unsubscribed_at'])): ?>
                                <div class="form-hint"><?= htmlspecialchars((string) $item['unsubscribed_at'], ENT_QUOTES) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars(mb_strtoupper((string) ($item['lang'] ?? '')), ENT_QUOTES) ?></strong>
                        <div class="form-hint"><?= htmlspecialchars((string) ($item['source'] ?? 'website'), ENT_QUOTES) ?></div>
                    </td>
                    <td><?= !empty($item['consent_at']) ? htmlspecialchars((string) $item['consent_at'], ENT_QUOTES) : 'Не зафиксировано' ?></td>
                    <td><?= !empty($item['last_digest_at']) ? htmlspecialchars((string) $item['last_digest_at'], ENT_QUOTES) : 'Ещё не формировался' ?></td>
                    <td class="data-table__actions">
                        <form method="post" action="/admin/subscribers/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить подписчика «<?= htmlspecialchars((string) $item['email'], ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?= \App\Core\View::renderPartial('admin/layout/pagination', [
    'paginationPath' => '/admin/subscribers',
    'filterParams' => $filterParams,
    'page' => $page,
    'pages' => $pages,
    'total' => $total,
]) ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
