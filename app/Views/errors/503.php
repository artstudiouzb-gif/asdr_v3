<?php $errorLang = \App\Core\Locale::current(); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($errorLang, ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#173a63">
<title>503 — <?= htmlspecialchars(t('Сервис временно недоступен'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/assets/css/system.css">
</head>
<body class="system-error system-error--503">
<main>
    <div class="status" role="status"><?= htmlspecialchars(t('Временные технические работы'), ENT_QUOTES) ?></div>
    <p class="code" aria-hidden="true">503</p>
    <h1><?= htmlspecialchars(t('Сервис временно недоступен'), ENT_QUOTES) ?></h1>
    <p><?= htmlspecialchars(t('Не удалось подключиться к одному из компонентов сайта. Пожалуйста, повторите попытку через минуту.'), ENT_QUOTES) ?></p>
    <div class="actions">
        <a href=""><?= htmlspecialchars(t('Повторить попытку'), ENT_QUOTES) ?></a>
        <a class="secondary" href="/"><?= htmlspecialchars(t('На главную'), ENT_QUOTES) ?></a>
    </div>
    <p class="hint"><?= htmlspecialchars(t('Код состояния:'), ENT_QUOTES) ?> 503.</p>
</main>
</body>
</html>
