<?php

use App\Core\Csrf;
use App\Core\Locale;
use App\Models\Language;

/** @var string|null $error */
$activeLangs = Language::active();
$currentLang = Locale::current();
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход в панель управления — <?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?></title>
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

<a href="/" class="auth-site-link">
    <?= \App\Core\AdminUi::icon('arrow-left', 16) ?>
    Вернуться на сайт
</a>

<?php if (count($activeLangs) > 1): ?>
    <div class="auth-lang-switcher">
        <?php foreach ($activeLangs as $l): ?>
            <?php
            $code = (string) $l['code'];
            $name = (string) $l['name'];
            $isActive = $code === $currentLang;
            $href = Locale::url('/admin/login', $code) . '?' . \App\Core\LocalePreference::QUERY . '=' . rawurlencode($code);
            ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>" class="auth-lang-btn<?= $isActive ? ' is-active' : '' ?>">
                <?= htmlspecialchars($name, ENT_QUOTES) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="auth-card">
    <div class="auth-brand">
        <?= \App\Core\AdminBrand::badgeHtml('auth-brand__logoimg', 'auth-brand__logo') ?>
        <span class="auth-brand__name"><?= htmlspecialchars(\App\Core\AdminBrand::name(), ENT_QUOTES) ?></span>
    </div>

    <div class="auth-head">
        <h1>Панель управления</h1>
        <p class="auth-sub">Введите учётные данные для доступа в систему</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert--error" role="alert">
            <?= \App\Core\AdminUi::icon('warning', 18, 'alert__icon') ?>
            <span><?= htmlspecialchars($error, ENT_QUOTES) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/login" class="auth-form">
        <?= Csrf::field() ?>
        <div class="auth-field">
            <label for="username">Логин</label>
            <div class="auth-input-wrap">
                <?= \App\Core\AdminUi::icon('user', 18, 'auth-input-icon') ?>
                <input type="text" id="username" name="username" autocomplete="username" placeholder="имя пользователя" required autofocus>
            </div>
        </div>

        <div class="auth-field">
            <div class="auth-field__head">
                <label for="password">Пароль</label>
                <a href="/admin/forgot" class="auth-forgot-link">Забыли пароль?</a>
            </div>
            <div class="auth-input-wrap">
                <?= \App\Core\AdminUi::icon('lock', 18, 'auth-input-icon') ?>
                <input type="password" id="password" name="password" autocomplete="current-password" placeholder="••••••••" required>
                <button type="button" class="auth-eye-btn" aria-label="Показать пароль" title="Показать пароль">
                    <?= \App\Core\AdminUi::icon('eye', 18, 'auth-eye-icon auth-eye-icon--show') ?>
                    <?= \App\Core\AdminUi::icon('eye-off', 18, 'auth-eye-icon auth-eye-icon--hide') ?>
                </button>
            </div>
            <div class="auth-caps-warning" id="caps-warning">
                <?= \App\Core\AdminUi::icon('warning', 14) ?>
                <span>Включён Caps Lock</span>
            </div>
        </div>

        <button type="submit" class="auth-submit-btn">
            <span>Войти в систему</span>
            <?= \App\Core\AdminUi::icon('arrow-right', 18) ?>
        </button>
    </form>

    <div class="auth-security-badge">
        <?= \App\Core\AdminUi::icon('lock', 15) ?>
        <span>Защищённое соединение &bull; двухфакторный вход</span>
    </div>
</div>

<script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    // 1. Показать / Скрыть пароль
    var eyeBtn = document.querySelector('.auth-eye-btn');
    var pwdInput = document.getElementById('password');
    if (eyeBtn && pwdInput) {
        var showIcon = eyeBtn.querySelector('.auth-eye-icon--show');
        var hideIcon = eyeBtn.querySelector('.auth-eye-icon--hide');
        eyeBtn.addEventListener('click', function() {
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                showIcon.style.display = 'none';
                hideIcon.style.display = 'block';
            } else {
                pwdInput.type = 'password';
                showIcon.style.display = 'block';
                hideIcon.style.display = 'none';
            }
        });

        // 2. Детектор Caps Lock
        var capsWarning = document.getElementById('caps-warning');
        if (capsWarning) {
            pwdInput.addEventListener('keyup', function(e) {
                if (e.getModifierState && e.getModifierState('CapsLock')) {
                    capsWarning.style.display = 'flex';
                } else {
                    capsWarning.style.display = 'none';
                }
            });
            pwdInput.addEventListener('keydown', function(e) {
                if (e.getModifierState && e.getModifierState('CapsLock')) {
                    capsWarning.style.display = 'flex';
                } else {
                    capsWarning.style.display = 'none';
                }
            });
        }
    }
});
</script>
</body>
</html>
