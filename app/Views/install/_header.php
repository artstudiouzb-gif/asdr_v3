<?php
/** @var string $step */
/** @var string $heading */
$step = $step ?? '1';
$heading = $heading ?? 'Установка';
$steps = [
    '1' => ['title' => 'Окружение', 'desc' => 'Проверка PHP и прав'],
    '2' => ['title' => 'База данных', 'desc' => 'Подключение MySQL'],
    '3' => ['title' => 'Сайт', 'desc' => 'Настройки системы'],
    '4' => ['title' => 'Администратор', 'desc' => 'Учётная запись'],
];
$stepNumber = (int) $step;
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Установка ASDR CMS — <?= htmlspecialchars($steps[$step]['title'] ?? $heading, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= file_exists(dirname(__DIR__, 2) . '/public/assets/css/admin.css') ? filemtime(dirname(__DIR__, 2) . '/public/assets/css/admin.css') : '2.0.1' ?>">
</head>
<body class="auth-page">
<div class="install-wrapper">
    <div class="install-brand">
        <div class="install-brand__logo">
            <?= \App\Core\Icon::render('layers-subtract', 22, 'install-brand__icon', 2.5) ?>
            <span class="install-brand__name">ASDR CMS</span>
            <span class="install-brand__badge">v2.0</span>
        </div>
    </div>

    <div class="auth-card auth-card--wide install-card">
        <div class="install-stepper">
            <?php foreach ($steps as $n => $info): ?>
                <?php
                $num = (int) $n;
                $isDone = $num < $stepNumber;
                $isActive = $num === $stepNumber;
                ?>
                <div class="install-step-item <?= $isDone ? 'is-done' : '' ?> <?= $isActive ? 'is-active' : '' ?>">
                    <div class="install-step-badge">
                        <?= $isDone ? \App\Core\Icon::render('check', 15, 'install-step-check', 2.5) : $n ?>
                    </div>
                    <span class="install-step-title"><?= htmlspecialchars($info['title'], ENT_QUOTES) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
