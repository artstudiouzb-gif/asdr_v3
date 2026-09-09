<?php

use App\Core\Csrf;

/** @var string|null $error */
$repoName = htmlspecialchars((string) \App\Models\Setting::get('site_name', 'Файловый портал'), ENT_QUOTES);
$repoLogo = trim((string) \App\Models\Setting::get('repo_logo', ''));

// Экран входа тоже обязан приходить в выбранном виде: настройки
// отображения посетитель задаёт на сайте, а логинится здесь.
$a11yAttributes = \App\Core\A11ySettings::htmlAttributes(
    \App\Core\A11ySettings::fromCookie($_COOKIE[\App\Core\A11ySettings::COOKIE] ?? null)
);
?><!doctype html>
<html lang="ru"<?= $a11yAttributes !== '' ? ' ' . $a11yAttributes : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= \App\Core\Icon::browserConfigHtml() ?>
    <title>Вход — Защищённое хранилище</title>
    <link rel="stylesheet" href="/assets/css/repo.css">
    <link rel="stylesheet" href="/assets/css/a11y.css">
</head>
<body class="repo-auth">
<div class="repo-auth__card">
    <div class="repo-auth__brand">
        <?php if ($repoLogo !== ''): ?>
            <img src="<?= htmlspecialchars($repoLogo, ENT_QUOTES) ?>" alt="<?= $repoName ?>" class="repo-auth__logo">
        <?php else: ?>
            <?= \App\Core\Icon::render('shield-check', 26) ?>
        <?php endif; ?>
        <span>Защищённое хранилище</span>
    </div>
    <p class="repo-auth__sub">Вход в файловый портал <?= $repoName ?></p>

    <?php if (!empty($error)): ?>
        <div class="repo-alert repo-alert--error"><?= htmlspecialchars((string) $error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="post" action="/repo/login">
        <?= Csrf::field() ?>
        <div class="repo-field">
            <label for="username">Логин</label>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>
        </div>
        <div class="repo-field">
            <label for="password">Пароль</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="repo-btn">Войти</button>
    </form>
    <p class="repo-auth__foot">Доступ предоставляется администратором.</p>
</div>
</body>
</html>
