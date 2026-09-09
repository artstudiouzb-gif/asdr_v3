<?php

use App\Core\Csrf;

/** @var string|null $error */
/** @var bool $sent */
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Восстановление пароля — <?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?></title>
<?= \App\Core\AdminUi::fontLinks() ?>
<link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Asset::url('/assets/css/admin.css'), ENT_QUOTES) ?>">
<?= \App\Core\AdminBrand::styleTag() ?>
<?= \App\Core\AdminBrand::faviconHtml() ?>
</head>
<body class="auth-page">
<div class="auth-decor">
    <div class="auth-decor__blob auth-decor__blob--1"></div>
    <div class="auth-decor__blob auth-decor__blob--2"></div>
    <div class="auth-decor__grid"></div>
</div>

<a href="/admin/login" class="auth-site-link">
    <?= \App\Core\AdminUi::icon('arrow-left', 16) ?>
    Вернуться к авторизации
</a>

<div class="auth-card">
    <div class="auth-brand">
        <?= \App\Core\AdminBrand::badgeHtml('auth-brand__logoimg', 'auth-brand__logo') ?>
        <span class="auth-brand__name"><?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?></span>
    </div>

    <div class="auth-head">
        <h1>Сброс пароля</h1>
        <p class="auth-sub">Укажите ваш зарегистрированный e-mail для получения ссылки</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if (!empty($sent)): ?>
        <div class="alert alert--success">
            Если такой e-mail зарегистрирован, мы отправили на него ссылку для сброса пароля.
            Ссылка действительна 30 минут.
        </div>
        <p class="u-inline-b7a1536112"><a href="/admin/login" class="auth-forgot-link">← Вернуться ко входу</a></p>
    <?php else: ?>
        <form method="post" action="/admin/forgot" class="auth-form">
            <?= Csrf::field() ?>
            <div class="auth-field">
                <label for="email">E-mail адрес</label>
                <div class="auth-input-wrap">
                    <?= \App\Core\AdminUi::icon('email', 18, 'auth-input-icon') ?>
                    <input type="email" id="email" name="email" autocomplete="email" placeholder="name@agency.gov.uz" required autofocus>
                </div>
            </div>

            <button type="submit" class="auth-submit-btn">
                <span>Отправить ссылку</span>
                <?= \App\Core\AdminUi::icon('send', 18) ?>
            </button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
