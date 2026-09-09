<?php

use App\Core\Csrf;

/** @var string|null $error */
/** @var string $token */
/** @var bool $invalid */
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Новый пароль</title>
<?= \App\Core\AdminUi::fontLinks() ?>
<link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Asset::url('/assets/css/admin.css'), ENT_QUOTES) ?>">
<?= \App\Core\AdminBrand::styleTag() ?>
<?= \App\Core\AdminBrand::faviconHtml() ?>
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-brand">
        <?= \App\Core\AdminBrand::badgeHtml('auth-brand__logoimg', 'auth-brand__logo') ?>
        <span class="auth-brand__name"><?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?></span>
    </div>
    <h1>Новый пароль</h1>
    <?php if (!empty($error)): ?>
        <div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php if (!empty($invalid)): ?>
        <p><a href="/admin/forgot">Запросить новую ссылку</a></p>
    <?php else: ?>
        <p class="form-hint">Минимум 10 символов, не короче двух групп символов, не из списка популярных паролей.</p>
        <form method="post" action="/admin/reset">
            <?= Csrf::field() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <label for="password">Новый пароль</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required autofocus>
            <label for="password_confirm">Повторите пароль</label>
            <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
            <button type="submit">Сохранить пароль</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
