<?php $errorLang = \App\Core\Locale::current(); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($errorLang, ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>500 — <?= htmlspecialchars(t('Внутренняя ошибка сервера'), ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/assets/css/system.css">
</head>
<body class="system-error system-error--500">
<h1>500</h1>
<p><?= htmlspecialchars(t('Произошла внутренняя ошибка сервера. Мы уже работаем над её устранением.'), ENT_QUOTES) ?></p>
<a href="/"><?= htmlspecialchars(t('На главную'), ENT_QUOTES) ?></a>
</body>
</html>
