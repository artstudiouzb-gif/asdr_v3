<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Core\WebhookDispatcher;

$pageTitle = 'Вебхуки';
$activeNav = 'webhooks';
require __DIR__ . '/../layout/header.php';

/** @var array<int,array<string,mixed>> $items */
/** @var array<int,array<string,mixed>> $deliveries */
/** @var list<string> $events */
/** @var array{pending:int,sent:int,failed:int} $queueCounts */
/** @var array{last:?int,age:?int,stale:bool,expected:int}|null $workerStatus */
$items = $items ?? [];
$deliveries = $deliveries ?? [];
$events = $events ?? [];
$queueCounts = $queueCounts ?? ['pending' => 0, 'sent' => 0, 'failed' => 0];
$workerStatus = $workerStatus ?? null;
$statusFilter = $statusFilter ?? '';

$eventLabels = [
    'form.submitted' => 'Новая заявка из формы',
    'news.published' => 'Опубликована новая новость',
];
$statusLabels = [
    'pending' => ['draft', 'В очереди'],
    'sent' => ['success', 'Доставлено'],
    'failed' => ['danger', 'Ошибка'],
];
$fmtAge = static function (?int $seconds): string {
    if ($seconds === null) {
        return 'нет данных';
    }
    if ($seconds < 60) {
        return $seconds . ' с назад';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' мин назад';
    }

    return intdiv($seconds, 3600) . ' ч назад';
};
$workerHealthy = $workerStatus !== null
    && $workerStatus['last'] !== null
    && empty($workerStatus['stale']);
$appRoot = defined('APP_ROOT') ? APP_ROOT : '/path/to/site';
?>

<p class="form-hint admin-section-intro">
    Вебхук автоматически передаёт событие с сайта во внешнюю систему: CRM,
    аналитику, интеграционную платформу или другой сервис. Если такой интеграции
    у вас нет, этот раздел можно оставить пустым.
</p>

<nav class="settings-jump-nav" aria-label="Разделы вебхуков">
    <a href="#webhook-guide"><?= AdminUi::icon('info', 16) ?>Как это работает</a>
    <a href="#webhook-endpoints"><?= AdminUi::icon('webhooks', 16) ?>Получатели</a>
    <a href="#webhook-add"><?= AdminUi::icon('plus', 16) ?>Добавить</a>
    <a href="#webhook-log"><?= AdminUi::icon('list', 16) ?>Журнал</a>
</nav>

<section class="form-card" id="webhook-guide">
    <h2 class="u-inline-291b7bbb01">Как это работает</h2>
    <div class="webhook-flow">
        <div class="webhook-flow__step">
            <span class="webhook-flow__number">1</span>
            <div>
                <strong>На сайте происходит событие</strong>
                <p class="form-hint">Например, посетитель отправил форму или редактор впервые опубликовал новость.</p>
            </div>
        </div>
        <div class="webhook-flow__step">
            <span class="webhook-flow__number">2</span>
            <div>
                <strong>CMS формирует JSON</strong>
                <p class="form-hint">Событие ставится в очередь и отправляется методом POST. Страница посетителя не ждёт ответа внешнего сервиса.</p>
            </div>
        </div>
        <div class="webhook-flow__step">
            <span class="webhook-flow__number">3</span>
            <div>
                <strong>Внешняя система принимает данные</strong>
                <p class="form-hint">Получатель отвечает HTTP 2xx. При временной ошибке CMS автоматически делает до трёх попыток.</p>
            </div>
        </div>
    </div>

    <details class="webhook-tech-details">
        <summary>Технические данные для разработчика интеграции</summary>
        <div class="webhook-tech-details__body">
            <p>Запрос: <code>POST</code>, заголовок <code>Content-Type: application/json</code>.</p>
            <p>
                Подпись: <code><?= htmlspecialchars(WebhookDispatcher::SIGNATURE_HEADER, ENT_QUOTES) ?>: sha256=…</code>.
                Значение считается как HMAC-SHA256 от неизменённого тела запроса с общим секретом.
            </p>
            <pre><code>{
  "event": "form.submitted",
  "timestamp": "2026-07-30T10:00:00Z",
  "data": {
    "form": "contact",
    "form_name": "Обратная связь",
    "fields": {"name": "…", "phone": "…"}
  }
}</code></pre>
            <p class="form-hint">
                Тестовая кнопка отправляет отдельное безопасное событие
                <code>webhook.test</code>, а не настоящую заявку или новость.
            </p>
        </div>
    </details>
</section>

<section class="form-card" id="webhook-endpoints">
    <div class="webhook-section__heading">
        <div>
            <h2 class="u-inline-291b7bbb01">Получатели</h2>
            <p class="form-hint">Каждый получатель подписывается только на одно выбранное событие.</p>
        </div>
        <span class="badge badge--<?= empty($items) ? 'draft' : 'success' ?>">
            <?= count($items) ?> подключено
        </span>
    </div>

    <?php if (empty($items)): ?>
        <div class="data-table__empty">
            Получателей пока нет. Добавьте их только по инструкции разработчика CRM или другого внешнего сервиса.
        </div>
    <?php endif; ?>

    <div class="webhook-endpoint-list">
        <?php foreach ($items as $w): ?>
            <?php
            $id = (int) $w['id'];
            $event = (string) $w['event_type'];
            $active = (int) $w['is_active'] === 1;
            ?>
            <article class="webhook-endpoint">
                <div class="webhook-endpoint__main">
                    <div class="webhook-endpoint__title">
                        <strong><?= htmlspecialchars($eventLabels[$event] ?? $event, ENT_QUOTES) ?></strong>
                        <code><?= htmlspecialchars($event, ENT_QUOTES) ?></code>
                    </div>
                    <div class="webhook-endpoint__url"><?= htmlspecialchars((string) $w['url'], ENT_QUOTES) ?></div>
                    <div class="webhook-endpoint__meta">
                        <span class="badge badge--<?= $active ? 'success' : 'draft' ?>">
                            <?= $active ? 'Активен' : 'Отключён' ?>
                        </span>
                        <span class="badge badge--<?= !empty($w['secret_configured']) ? 'published' : 'draft' ?>">
                            <?= !empty($w['secret_configured']) ? 'HMAC защищён' : 'Без подписи' ?>
                        </span>
                    </div>
                </div>
                <div class="webhook-endpoint__actions">
                    <form method="post" action="/admin/webhooks/<?= $id ?>/test"
                          data-confirm="Отправить безопасное событие webhook.test этому получателю?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--small btn--outline">
                            <?= AdminUi::icon('send') ?>Проверить
                        </button>
                    </form>
                    <details class="webhook-edit">
                        <summary class="btn btn--small"><?= AdminUi::icon('edit') ?>Настроить</summary>
                        <form method="post" action="/admin/webhooks/<?= $id ?>/edit" class="form-grid">
                            <?= Csrf::field() ?>
                            <div class="form-field">
                                <label for="event_type_<?= $id ?>">Событие</label>
                                <select id="event_type_<?= $id ?>" name="event_type" required>
                                    <?php foreach ($events as $ev): ?>
                                        <option value="<?= htmlspecialchars($ev, ENT_QUOTES) ?>" <?= $event === $ev ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($eventLabels[$ev] ?? $ev, ENT_QUOTES) ?> · <?= htmlspecialchars($ev, ENT_QUOTES) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label for="url_<?= $id ?>">Публичный HTTPS URL</label>
                                <input type="url" id="url_<?= $id ?>" name="url" maxlength="500"
                                       value="<?= htmlspecialchars((string) $w['url'], ENT_QUOTES) ?>" required>
                            </div>
                            <div class="form-field">
                                <label for="secret_<?= $id ?>">Новый общий секрет</label>
                                <input type="password" id="secret_<?= $id ?>" name="secret"
                                       minlength="16" maxlength="500" autocomplete="new-password"
                                       placeholder="<?= !empty($w['secret_configured']) ? 'Сохранён — оставьте пустым без изменений' : 'Не менее 16 символов' ?>">
                                <?php if (!empty($w['secret_configured'])): ?>
                                    <label class="form-hint">
                                        <input type="checkbox" name="clear_secret" value="1"> Удалить сохранённый секрет
                                    </label>
                                <?php endif; ?>
                            </div>
                            <div class="form-field form-field--checkbox">
                                <input type="checkbox" id="is_active_<?= $id ?>" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
                                <label for="is_active_<?= $id ?>">Принимать новые события</label>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary"><?= AdminUi::icon('save') ?>Сохранить</button>
                            </div>
                        </form>
                    </details>
                    <form method="post" action="/admin/webhooks/<?= $id ?>/delete"
                          data-confirm="Удалить получателя вместе со всей его очередью и журналом доставок?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--small btn--danger">
                            <?= AdminUi::icon('trash') ?>Удалить
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="form-card" id="webhook-add">
    <h2 class="u-inline-291b7bbb01">Добавить получателя</h2>
    <p class="form-hint">
        URL и общий секрет должен предоставить разработчик принимающей системы.
        Используйте отдельный секрет для каждого получателя.
    </p>
    <form method="post" action="/admin/webhooks/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="event_type">Что отправлять</label>
            <select id="event_type" name="event_type" required>
                <?php foreach ($events as $ev): ?>
                    <option value="<?= htmlspecialchars($ev, ENT_QUOTES) ?>">
                        <?= htmlspecialchars($eventLabels[$ev] ?? $ev, ENT_QUOTES) ?> · <?= htmlspecialchars($ev, ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="url">Куда отправлять — публичный HTTPS URL</label>
            <input type="url" id="url" name="url" maxlength="500"
                   placeholder="https://crm.example.uz/api/asdr-webhook" required>
            <span class="form-hint">Локальные адреса и обычный HTTP запрещены, поскольку данные формы могут содержать персональную информацию.</span>
        </div>
        <div class="form-field">
            <label for="secret">Общий секрет для проверки подписи</label>
            <input type="password" id="secret" name="secret" minlength="16" maxlength="500"
                   autocomplete="new-password" placeholder="Не менее 16 случайных символов">
            <span class="form-hint">Необязательно, но настоятельно рекомендуется. Один и тот же секрет указывается здесь и в принимающей системе.</span>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_active" name="is_active" value="1" checked>
            <label for="is_active">Начать отправку сразу после добавления</label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('plus') ?>Добавить получателя</button>
        </div>
    </form>
</section>

<section class="form-card" id="webhook-log">
    <div class="webhook-section__heading">
        <div>
            <h2 class="u-inline-291b7bbb01">Очередь и журнал доставок</h2>
            <p class="form-hint">
                Cron запускает воркер каждую минуту. Ручной запуск обрабатывает до 5 ожидающих доставок.
            </p>
        </div>
        <form method="post" action="/admin/webhooks/run"
              data-confirm="Запустить обработку очереди вебхуков сейчас?">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('send') ?>Отправить очередь сейчас</button>
        </form>
    </div>

    <div class="webhook-status-grid">
        <div>
            <span class="form-hint">Воркер</span>
            <strong class="<?= $workerHealthy ? 'admin-status--success' : '' ?>">
                <?php if ($workerHealthy): ?>
                    Работает · <?= htmlspecialchars($fmtAge($workerStatus['age']), ENT_QUOTES) ?>
                <?php elseif ($workerStatus !== null && $workerStatus['last'] !== null): ?>
                    Не запускался <?= htmlspecialchars($fmtAge($workerStatus['age']), ENT_QUOTES) ?>
                <?php else: ?>
                    Нет данных о запуске
                <?php endif; ?>
            </strong>
        </div>
        <div><span class="form-hint">В очереди</span><strong><?= (int) $queueCounts['pending'] ?></strong></div>
        <div><span class="form-hint">Доставлено</span><strong><?= (int) $queueCounts['sent'] ?></strong></div>
        <div><span class="form-hint">Ошибок</span><strong><?= (int) $queueCounts['failed'] ?></strong></div>
    </div>

    <?php if (!$workerHealthy): ?>
        <details class="webhook-tech-details">
            <summary>Как настроить Cron-воркер</summary>
            <div class="webhook-tech-details__body">
                <p>Добавьте на хостинге задание с периодичностью раз в минуту:</p>
                <code>* * * * * php <?= htmlspecialchars($appRoot, ENT_QUOTES) ?>/app/Console/webhook_worker.php &gt;&gt; <?= htmlspecialchars($appRoot, ENT_QUOTES) ?>/storage/logs/webhook_worker.log 2&gt;&amp;1</code>
            </div>
        </details>
    <?php endif; ?>

    <?php $sf = $statusFilter; ?>
    <div class="filter-tabs u-inline-3ef1fa1aa1">
        <a class="btn btn--small <?= $sf === '' ? 'btn--primary' : '' ?>" href="/admin/webhooks#webhook-log">Все</a>
        <a class="btn btn--small <?= $sf === 'pending' ? 'btn--primary' : '' ?>" href="/admin/webhooks?status=pending#webhook-log">В очереди</a>
        <a class="btn btn--small <?= $sf === 'sent' ? 'btn--primary' : '' ?>" href="/admin/webhooks?status=sent#webhook-log">Доставленные</a>
        <a class="btn btn--small <?= $sf === 'failed' ? 'btn--primary' : '' ?>" href="/admin/webhooks?status=failed#webhook-log">Ошибки</a>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>Дата</th>
                <th>Событие</th>
                <th>Получатель</th>
                <th>Результат</th>
                <th>Попыток</th>
                <th>Подробности</th>
                <th>Действие</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($deliveries)): ?>
                <tr><td colspan="7" class="data-table__empty">Доставок с выбранным статусом пока нет.</td></tr>
            <?php endif; ?>
            <?php foreach ($deliveries as $d): ?>
                <?php
                $status = (string) ($d['status'] ?? '');
                [$badgeClass, $statusLabel] = $statusLabels[$status] ?? ['draft', $status];
                $payload = json_decode((string) ($d['payload_json'] ?? ''), true);
                $prettyPayload = is_array($payload)
                    ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) ($d['payload_json'] ?? '');
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($d['sent_at'] ?? $d['created_at'] ?? ''), ENT_QUOTES) ?></td>
                    <td>
                        <?= htmlspecialchars($eventLabels[(string) $d['event_type']] ?? (string) $d['event_type'], ENT_QUOTES) ?>
                        <code><?= htmlspecialchars((string) $d['event_type'], ENT_QUOTES) ?></code>
                    </td>
                    <td class="u-inline-6581ac980a"><?= htmlspecialchars((string) ($d['webhook_url'] ?? ''), ENT_QUOTES) ?></td>
                    <td>
                        <span class="badge badge--<?= $badgeClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES) ?></span>
                        <?php if ($d['response_code'] !== null): ?>
                            <span class="form-hint">HTTP <?= (int) $d['response_code'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($d['attempts'] ?? 0) ?>/3</td>
                    <td>
                        <details class="webhook-delivery-details">
                            <summary>Показать</summary>
                            <?php if (!empty($d['last_error'])): ?>
                                <p class="webhook-error"><?= htmlspecialchars((string) $d['last_error'], ENT_QUOTES) ?></p>
                            <?php endif; ?>
                            <pre><code><?= htmlspecialchars(is_string($prettyPayload) ? $prettyPayload : '', ENT_QUOTES) ?></code></pre>
                        </details>
                    </td>
                    <td>
                        <?php if ($status === 'failed'): ?>
                            <form method="post" action="/admin/webhooks/deliveries/<?= (int) $d['id'] ?>/retry">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn--small btn--outline">
                                    <?= AdminUi::icon('reset') ?>Повторить
                                </button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
