<?php

use App\Core\Auth;
use App\Core\Csrf;

$pageTitle = 'Пользователи';
$activeNav = 'users';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var string|null $error */

$roleLabels = ['admin' => 'Супер-администратор', 'editor' => 'Редактор'];
?>
<p class="form-hint">Редактор управляет только контентом (страницы, новости, проекты, команда, формы, файлы). Системные разделы доступны супер-администратору. Ушедшего сотрудника лучше заблокировать, чем удалять: авторство его материалов и журналы сохранятся.</p>

<table class="data-table u-inline-5370cbf1a7">
    <thead>
        <tr><th>Логин</th><th>Email</th><th>Роль</th><th>Статус</th><th>Второй фактор</th><th>Последний вход</th><th></th></tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item):
            $uid = (int) $item['id'];
            $isSelf = $uid === Auth::id();
            $active = (int) ($item['is_active'] ?? 1) === 1;
            $factors = array_filter([
                !empty($item['totp_enabled']) ? 'приложение' : '',
                !empty($item['telegram_chat_id']) ? 'Telegram' : '',
            ]);
            $name = htmlspecialchars((string) $item['username'], ENT_QUOTES);
        ?>
            <tr>
                <td><?= $name ?></td>
                <td><?= htmlspecialchars((string) $item['email'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($roleLabels[$item['role']] ?? $item['role'], ENT_QUOTES) ?></td>
                <td><span class="badge badge--<?= $active ? 'success' : 'danger' ?>"><?= $active ? 'Активен' : 'Заблокирован' ?></span></td>
                <td><?= $factors !== [] ? htmlspecialchars(implode(', ', $factors), ENT_QUOTES) : '—' ?></td>
                <td><?= htmlspecialchars((string) ($item['last_login_at'] ?? '—'), ENT_QUOTES) ?></td>
                <td class="data-table__actions">
                    <?php if ($isSelf): ?>
                        <span class="form-hint">это вы</span>
                    <?php else: ?>
                        <form method="post" action="/admin/users/<?= $uid ?>/active"<?= $active ? ' data-confirm="Заблокировать «' . $name . '»? Его открытые сессии завершатся."' : '' ?>>
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--secondary"><?= \App\Core\AdminUi::icon($active ? 'lock' : 'lock-open') ?><?= $active ? 'Заблокировать' : 'Разблокировать' ?></button>
                        </form>
                        <?php if ($factors !== []): ?>
                            <form method="post" action="/admin/users/<?= $uid ?>/reset-2fa" data-confirm="Сбросить второй фактор «<?= $name ?>»? При следующем входе он подключит его заново.">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn--small btn--secondary"><?= \App\Core\AdminUi::icon('shield-off') ?>Сбросить 2FA</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/admin/users/<?= $uid ?>/delete" data-confirm="Удалить «<?= $name ?>» навсегда? Его новости останутся без автора — обычно достаточно заблокировать.">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2 class="u-inline-291b7bbb01">Изменить данные</h2>
<?php foreach ($items as $item):
    $uid = (int) $item['id'];
?>
    <details class="form-card">
        <summary><?= htmlspecialchars((string) $item['username'], ENT_QUOTES) ?></summary>
        <form method="post" action="/admin/users/<?= $uid ?>/update" class="form-grid">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label for="email-<?= $uid ?>">Email</label>
                <input type="email" id="email-<?= $uid ?>" name="email" required value="<?= htmlspecialchars((string) $item['email'], ENT_QUOTES) ?>">
            </div>
            <div class="form-field">
                <label for="role-<?= $uid ?>">Роль</label>
                <select id="role-<?= $uid ?>" name="role">
                    <option value="editor"<?= $item['role'] === 'editor' ? ' selected' : '' ?>>Редактор (только контент)</option>
                    <option value="admin"<?= $item['role'] === 'admin' ? ' selected' : '' ?>>Супер-администратор (полный доступ)</option>
                </select>
            </div>
            <div class="form-field">
                <label for="phone-<?= $uid ?>">Телефон для кода входа</label>
                <input type="tel" id="phone-<?= $uid ?>" name="phone" placeholder="+998901234567" autocomplete="off" value="<?= htmlspecialchars((string) ($item['phone'] ?? ''), ENT_QUOTES) ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
            </div>
        </form>
    </details>
<?php endforeach; ?>

<div class="form-card">
    <h2 class="u-inline-291b7bbb01">Добавить пользователя</h2>
    <?php if (!empty($error)): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="post" action="/admin/users/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="username">Логин</label>
            <input type="text" id="username" name="username" required autocomplete="off">
        </div>
        <div class="form-field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="form-field">
            <label for="phone">Телефон для кода входа (необязательно)</label>
            <input type="tel" id="phone" name="phone" placeholder="+998901234567" autocomplete="off">
            <span class="form-hint">Код подтверждения входа придёт в Telegram от канала Verification Codes. Без телефона — вход только по паролю.</span>
        </div>
        <div class="form-field">
            <label for="password">Пароль (минимум 10 символов)</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
        </div>
        <div class="form-field">
            <label for="role">Роль</label>
            <select id="role" name="role">
                <option value="editor">Редактор (только контент)</option>
                <option value="admin">Супер-администратор (полный доступ)</option>
            </select>
        </div>
        <div class="form-field">
            <label for="admin_lang">Язык админки по умолчанию</label>
            <select id="admin_lang" name="admin_lang">
                <option value="">— Системный по умолчанию —</option>
                <?php foreach (\App\Models\Language::active() as $l): ?>
                    <option value="<?= htmlspecialchars((string) $l['code'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars((string) $l['name'], ENT_QUOTES) ?> (<?= strtoupper(htmlspecialchars((string) $l['code'], ENT_QUOTES)) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Создать</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>