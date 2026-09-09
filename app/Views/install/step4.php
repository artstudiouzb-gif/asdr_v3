<?php

use App\Core\Csrf;

/** @var string|null $error */
/** @var array $data */
$step = '4';
require __DIR__ . '/_header.php';
?>
<div class="install-header">
    <h1 class="install-header__title">Создание главного администратора</h1>
    <p class="install-header__desc">Укажите учётные данные суперпользователя для доступа к панели управления.</p>
</div>

<div class="install-callout">
    <?= \App\Core\Icon::render('shield-lock', 20, 'install-callout__icon') ?>
    <div>
        <strong>Безопасность системы:</strong> При первом входе в панель управления система автоматически предложит настроить двухфакторную аутентификацию (2FA) для полной защиты вашей системы.
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert--error u-inline-d7a4b27f07">
        <?= htmlspecialchars($error, ENT_QUOTES) ?>
    </div>
<?php endif; ?>

<form method="post" action="/install/step4" class="form-grid">
    <?= Csrf::field() ?>

    <div class="form-grid-2col u-inline-d4be37b0b9">
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="username">Логин администратора <span class="u-inline-9dd1207e58">*</span></label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('user', 18, 'install-input-icon') ?>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($data['username'] ?? '', ENT_QUOTES) ?>" required autocomplete="username" placeholder="admin">
            </div>
        </div>
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="email">E-mail <span class="u-inline-9dd1207e58">*</span></label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('mail', 18, 'install-input-icon') ?>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($data['email'] ?? '', ENT_QUOTES) ?>" required placeholder="admin@example.com">
            </div>
        </div>
    </div>

    <div class="form-field u-inline-9eb125f52f">
        <label class="u-inline-aa1820469b" for="password">Пароль (минимум 10 символов) <span class="u-inline-9dd1207e58">*</span></label>
        <div class="install-input-wrap">
            <?= \App\Core\Icon::render('lock', 18, 'install-input-icon') ?>
            <input class="u-inline-614afdfeb3" type="password" id="password" name="password" required autocomplete="new-password" placeholder="Надёжный пароль">
            <button type="button" id="toggle_pass" class="auth-eye-btn u-inline-761ba8828b" title="Показать пароль" aria-label="Показать пароль">
                <?= \App\Core\Icon::render('eye', 18, 'auth-eye-icon auth-eye-icon--show') ?>
                <?= \App\Core\Icon::render('eye-off', 18, 'auth-eye-icon auth-eye-icon--hide u-inline-c8be1ccba6') ?>
            </button>
        </div>

        <div class="u-inline-d8a81eac84">
            <div class="u-inline-af9c75294f">
                <div class="u-inline-821081bbf8" id="pass_strength_bar"></div>
            </div>
            <div class="u-inline-3f6331b9d1" id="pass_strength_text">
                Введённый пароль
            </div>
        </div>
    </div>

    <div class="form-actions u-inline-9eb125f52f">
        <button type="submit" class="btn btn--primary u-inline-5b9236194b">
            <?= \App\Core\Icon::render('circle-check', 18, 'btn__icon', 2.5) ?>
            Завершить установку
        </button>
    </div>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
