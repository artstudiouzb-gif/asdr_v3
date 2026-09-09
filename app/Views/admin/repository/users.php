<?php

use App\Core\Csrf;

$pageTitle = 'Репозиторий: пользователи портала';
$activeNav = 'repository';
require __DIR__ . '/../layout/header.php';

/** @var array $users */
/** @var string|null $error */
?>
<div class="repo-subnav">
    <a href="/admin/repository" class="repo-subnav__item"><?= \App\Core\AdminUi::icon('file', 16) ?> Файлы</a>
    <a href="/admin/repository/categories" class="repo-subnav__item"><?= \App\Core\AdminUi::icon('folder', 16) ?> Категории</a>
    <a href="/admin/repository/users" class="repo-subnav__item is-active"><?= \App\Core\AdminUi::icon('users', 16) ?> Пользователи портала</a>
</div>

<p class="form-hint">Учётные записи для входа в файловый портал <code>/repo</code>. Это отдельные аккаунты, не связанные с пользователями админ-панели. 2FA пользователь включает самостоятельно после первого входа.</p>

<table class="data-table mb-4">
    <thead>
        <tr><th>Логин</th><th>Имя</th><th>Организация</th><th>Email</th><th>Статус</th><th>2FA</th><th>Последний вход</th><th></th></tr>
    </thead>
    <tbody>
        <?php if (empty($users)): ?>
            <tr><td class="text-center text-muted" colspan="8">Пользователей пока нет.</td></tr>
        <?php else: ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) $u['username'], ENT_QUOTES) ?></strong></td>
                    <td><?= $u['full_name'] !== '' ? htmlspecialchars((string) $u['full_name'], ENT_QUOTES) : '—' ?></td>
                    <td><?= ($u['organization'] ?? '') !== '' ? htmlspecialchars((string) $u['organization'], ENT_QUOTES) : '—' ?></td>
                    <td><?= htmlspecialchars((string) $u['email'], ENT_QUOTES) ?></td>
                    <td>
                        <?php if ((int) $u['is_active'] === 1): ?>
                            <span class="badge badge--success">Активен</span>
                        <?php else: ?>
                            <span class="badge badge--muted">Отключён</span>
                        <?php endif; ?>
                    </td>
                    <td><?= \App\Core\AdminUi::icon((int) $u['totp_enabled'] === 1 ? 'check' : 'minus', 14) ?></td>
                    <td class="form-hint"><?= htmlspecialchars((string) ($u['last_login_at'] ?? '—'), ENT_QUOTES) ?></td>
                    <td class="data-table__actions">
                        <form method="post" action="/admin/repository/users/<?= (int) $u['id'] ?>/toggle">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small"><?= (int) $u['is_active'] === 1 ? 'Отключить' : 'Включить' ?></button>
                        </form>
                        <div class="repo-popover">
                            <details>
                                <summary class="btn btn--small">Сбросить пароль</summary>
                                <div class="repo-popover__panel">
                                    <form method="post" action="/admin/repository/users/<?= (int) $u['id'] ?>/reset-password">
                                        <?= Csrf::field() ?>
                                        <div class="form-field mb-2">
                                            <label>Новый пароль</label>
                                            <input type="password" name="password" placeholder="Новый пароль" required autocomplete="new-password">
                                        </div>
                                        <button type="submit" class="btn btn--small btn--primary">Сбросить</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                        <form method="post" action="/admin/repository/users/<?= (int) $u['id'] ?>/delete" data-confirm="Удалить пользователя «<?= htmlspecialchars((string) $u['username'], ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<div class="form-card mb-4">
    <h2>Добавить пользователя портала</h2>
    <?php if (!empty($error)): ?><div class="alert alert--error mb-3"><?= htmlspecialchars((string) $error, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="post" action="/admin/repository/users/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="username">Логин</label>
            <input type="text" id="username" name="username" required autocomplete="off" placeholder="напр. user_partner">
        </div>
        <div class="form-field">
            <label for="full_name">Имя (необязательно)</label>
            <input type="text" id="full_name" name="full_name" placeholder="Иван Иванов">
        </div>
        <div class="form-field">
            <label for="organization">Организация (необязательно)</label>
            <input type="text" id="organization" name="organization" maxlength="190" placeholder="АО Партнёр">
        </div>
        <div class="form-field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required placeholder="user@example.com">
        </div>
        <div class="form-field">
            <label for="password">Пароль (минимум 10 символов)</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('plus') ?>Создать пользователя</button></div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
