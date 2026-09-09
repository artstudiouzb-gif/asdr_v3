<?php

use App\Core\Csrf;
use App\Core\Flash;

/** @var string $pageTitle */
/** @var array|null $repoUser */
$repoName = htmlspecialchars((string) \App\Models\Setting::get('site_name', 'Файловый портал'), ENT_QUOTES);
$repoLogo = trim((string) \App\Models\Setting::get('repo_logo', ''));

// Настройки отображения общие с основным сайтом и читаются одним классом.
// Прежде здесь жил разбор cookie прежней версии («cw:l:on») с атрибутами
// data-a11y-scheme и data-a11y-size="m|l|xl": ни того формата, ни тех правил
// в a11y.css давно нет, поэтому условие не срабатывало никогда и портал
// приходил без атрибутов. Настройки всё равно применялись — их доставлял
// a11y.js уже после загрузки, — но страница успевала нарисоваться обычной и
// прыгала в высокий контраст на глазах у того, кому он и нужен.
$a11ySettings = \App\Core\A11ySettings::fromCookie($_COOKIE[\App\Core\A11ySettings::COOKIE] ?? null);
$a11yActive = \App\Core\A11ySettings::isActive($a11ySettings);
$a11yAttributes = \App\Core\A11ySettings::htmlAttributes($a11ySettings);
?><!doctype html>
<html lang="ru"<?= $a11yAttributes !== '' ? ' ' . $a11yAttributes : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= \App\Core\Icon::browserConfigHtml() ?>
    <title><?= htmlspecialchars($pageTitle ?? 'Файловый портал', ENT_QUOTES) ?> — <?= $repoName ?></title>
    <link rel="stylesheet" href="/assets/css/noto-sans.css">
    <link rel="stylesheet" href="/assets/css/noto-serif.css">
    <link rel="stylesheet" href="/assets/css/gov-theme.css?v=<?= file_exists(dirname(__DIR__, 3) . '/public/assets/css/gov-theme.css') ? filemtime(dirname(__DIR__, 3) . '/public/assets/css/gov-theme.css') : '2.0.1' ?>">
    <link rel="stylesheet" href="/assets/css/repo.css?v=<?= file_exists(dirname(__DIR__, 3) . '/public/assets/css/repo.css') ? filemtime(dirname(__DIR__, 3) . '/public/assets/css/repo.css') : '2.0.1' ?>">
    <link rel="stylesheet" href="/assets/css/a11y.css">
</head>
<body>
<header class="repo-topbar">
    <a href="/repo" class="repo-topbar__brand">
        <?php if ($repoLogo !== ''): ?>
            <img src="<?= htmlspecialchars($repoLogo, ENT_QUOTES) ?>" alt="<?= $repoName ?>" class="repo-topbar__logo">
        <?php else: ?>
            <?= \App\Core\Icon::render('shield-check', 24) ?>
        <?php endif; ?>
        <span>Защищённое хранилище</span>
    </a>
    <nav>
        <?php // data-a11y-open обязателен: по нему a11y.js и открывает панель.
              // Без него кнопка была видна, но не делала ничего. ?>
        <button type="button" class="a11y-toggle<?= $a11yActive ? ' is-active' : '' ?>" data-a11y-open
                aria-label="Настройки отображения" title="Настройки отображения: размер текста, контраст, интервалы"
                aria-controls="a11y-panel" aria-expanded="false">
            <?= \App\Core\Icon::render('eye', 18) ?>
        </button>
        <?php if (!empty($repoUser)): ?>
            <span class="repo-topbar__user"><?= htmlspecialchars((string) ($repoUser['full_name'] ?: $repoUser['username']), ENT_QUOTES) ?></span>
            <a href="/repo">Файлы</a>
            <a href="/repo/security">Безопасность</a>
            <form class="repo-logout-form" method="post" action="/repo/logout">
                <?= Csrf::field() ?>
                <button type="submit">Выйти</button>
            </form>
        <?php endif; ?>
    </nav>
</header>
<?php
// Панель — тот же партиал, что и на публичной части. Прежде здесь лежала своя
// копия из прошлой версии: кнопки «scheme:cw» и «size:m|l|xl» нормализатор
// A11ySettings уже не знает (у размера теперь проценты 100–200), поэтому
// «Крупный» сбрасывал бы размер в 100, а выбор цвета не делал ничего. Плюс у
// класса .a11y-panel не осталось ни одного правила в CSS — разметка была
// мёртвой целиком. Копия и разъехалась бы снова при первой правке настроек.
require dirname(__DIR__, 2) . '/site/_a11y_panel.php';
?>
<main class="repo-main">
    <?php foreach (Flash::pull() as $flash): ?>
        <div class="repo-alert repo-alert--<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"
             role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"
             aria-live="<?= $flash['type'] === 'success' ? 'polite' : 'assertive' ?>">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES) ?>
        </div>
    <?php endforeach; ?>
