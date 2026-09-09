<?php

$pageTitle = 'Журнал действий';
$activeNav = 'audit';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var array $filters */
/** @var array $actors */

// Человекочитаемая метка раздела панели по началу пути.
$sectionLabels = [
    '/admin/settings/demo-content' => 'Демо-контент',
    '/admin/forms/submissions' => 'Заявки форм',
    '/admin/pages' => 'Страницы',
    '/admin/news' => 'Новости',
    '/admin/projects' => 'Проекты',
    '/admin/team' => 'Команда',
    '/admin/forms' => 'Формы',
    '/admin/files' => 'Файлы',
    '/admin/trash' => 'Корзина',
    '/admin/design' => 'Дизайн',
    '/admin/menu' => 'Меню',
    '/admin/widgets' => 'Виджеты',
    '/admin/header' => 'Шапка сайта',
    '/admin/footer' => 'Подвал сайта',
    '/admin/languages' => 'Языки',
    '/admin/content-types' => 'Типы контента',
    '/admin/content' => 'Контент',
    '/admin/social' => 'Соцсети',
    '/admin/telegram' => 'Telegram',
    '/admin/webhooks' => 'Вебхуки',
    '/admin/redirects' => 'Редиректы',
    '/admin/performance' => 'Производительность',
    '/admin/security' => 'Безопасность',
    '/admin/audit' => 'Журналы',
    '/admin/settings' => 'Настройки',
    '/admin/users' => 'Пользователи',
    '/admin/profile' => 'Профиль',
    '/admin/repository' => 'Хранилище',
    '/admin/backup' => 'Бэкапы',
    '/admin/logout' => 'Выход',
];
$sectionOf = static function (string $path) use ($sectionLabels): string {
    if (str_starts_with($path, 'auth/')) {
        return 'Вход и защита';
    }
    foreach ($sectionLabels as $prefix => $label) {
        if (str_starts_with($path, $prefix)) {
            return $label;
        }
    }
    return 'Панель';
};

// Query-строка текущих фильтров для ссылок пагинации.
$qs = static function (int $p) use ($filters): string {
    $params = array_filter([
        'user_id' => $filters['user_id'] ?: null,
        'method' => $filters['method'] !== '' ? $filters['method'] : null,
        'q' => $filters['q'] !== '' ? $filters['q'] : null,
        'from' => $filters['from'] !== '' ? $filters['from'] : null,
        'to' => $filters['to'] !== '' ? $filters['to'] : null,
        'page' => $p > 1 ? $p : null,
    ]);
    return $params === [] ? '/admin/audit' : '/admin/audit?' . http_build_query($params);
};
?>
<div class="u-inline-f94566b02a">
    <a class="btn btn--small" href="/admin/security">Центр безопасности</a>
    <a class="btn btn--small btn--primary" href="/admin/audit">Действия администраторов</a>
    <a class="btn btn--small" href="/admin/audit/errors">Ошибки сайта</a>
</div>

<p class="form-hint">Изменения в панели и события входа: кто, что, когда и с какого IP. Тела форм, пароли и токены не сохраняются. Записи старше <?= (int) \App\Models\AuditLog::RETENTION_DAYS ?> дней удаляются автоматически.</p>

<form method="get" action="/admin/audit" class="form-grid form-grid--inline u-inline-6f145d537e">
    <div class="form-field u-inline-1da9facb4d">
        <label for="f_user">Администратор</label>
        <select id="f_user" name="user_id">
            <option value="">— все —</option>
            <?php foreach ($actors as $actor): ?>
                <option value="<?= (int) $actor['user_id'] ?>" <?= $filters['user_id'] === (int) $actor['user_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $actor['username'], ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-field u-inline-1da9facb4d">
        <label for="f_method">Тип события</label>
        <select id="f_method" name="method">
            <option value="">— все —</option>
            <?php foreach (['AUTH' => 'Вход и защита', 'POST' => 'Изменение', 'PUT' => 'Обновление', 'PATCH' => 'Частичное обновление', 'DELETE' => 'Удаление'] as $method => $methodLabel): ?>
                <option value="<?= $method ?>" <?= $filters['method'] === $method ? 'selected' : '' ?>>
                    <?= htmlspecialchars($methodLabel, ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-field u-inline-1da9facb4d">
        <label for="f_q">Адрес или событие содержит</label>
        <input type="text" id="f_q" name="q" maxlength="200" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES) ?>" placeholder="например: pages или login">
    </div>
    <div class="form-field u-inline-1da9facb4d">
        <label for="f_from">С даты</label>
        <input type="date" id="f_from" name="from" value="<?= htmlspecialchars($filters['from'], ENT_QUOTES) ?>">
    </div>
    <div class="form-field u-inline-1da9facb4d">
        <label for="f_to">По дату</label>
        <input type="date" id="f_to" name="to" value="<?= htmlspecialchars($filters['to'], ENT_QUOTES) ?>">
    </div>
    <div class="form-actions u-inline-1da9facb4d">
        <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('filter') ?>Фильтровать</button>
        <a href="/admin/audit" class="btn"><?= \App\Core\AdminUi::icon('reset') ?>Сбросить</a>
    </div>
</form>

<p class="form-hint">Найдено записей: <strong><?= (int) $total ?></strong></p>

<?php if (empty($items)): ?>
    <p class="form-hint">Записей нет. Журнал наполняется по мере работы администраторов в панели.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Когда</th><th>Кто</th><th>Раздел</th><th>Действие</th><th>IP</th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                $method = strtoupper((string) ($item['method'] ?? ''));
                $path = (string) ($item['path'] ?? '');
                $authMeta = $method === 'AUTH' ? \App\Models\AuditLog::authEventMeta($path) : null;
                $actionLabels = [
                    'POST' => 'Изменение данных',
                    'PUT' => 'Обновление данных',
                    'PATCH' => 'Частичное обновление',
                    'DELETE' => 'Удаление данных',
                ];
                ?>
                <tr>
                    <td class="u-inline-a9efa5449f"><?= htmlspecialchars((string) $item['created_at'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) ($item['username'] ?: 'Не определён'), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($sectionOf($path), ENT_QUOTES) ?></td>
                    <td>
                        <?php if ($authMeta !== null): ?>
                            <span class="status-badge status-badge--<?= htmlspecialchars($authMeta['tone'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($authMeta['label'], ENT_QUOTES) ?>
                            </span>
                        <?php else: ?>
                            <strong><?= htmlspecialchars($actionLabels[$method] ?? $method, ENT_QUOTES) ?></strong>
                        <?php endif; ?>
                        <br><code class="u-inline-e71ae94b55"><?= htmlspecialchars($method . ' ' . $path, ENT_QUOTES) ?></code>
                    </td>
                    <td><?= htmlspecialchars((string) ($item['ip'] ?? '—'), ENT_QUOTES) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="u-inline-d2576ac843">
            <?php if ($page > 1): ?><a class="btn btn--small" href="<?= htmlspecialchars($qs($page - 1), ENT_QUOTES) ?>">← Новее</a><?php endif; ?>
            <span class="form-hint">Страница <?= (int) $page ?> из <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn--small" href="<?= htmlspecialchars($qs($page + 1), ENT_QUOTES) ?>">Старее →</a><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
