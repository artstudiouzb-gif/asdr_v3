<?php

use App\Models\Setting;

$siteName = Setting::get('site_name', 'ASDR');
$message = Setting::get('maintenance_message', 'Сайт временно закрыт на техническое обслуживание. Мы скоро вернёмся.');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= \App\Core\Icon::browserConfigHtml() ?>
<title><?= htmlspecialchars($siteName, ENT_QUOTES) ?> — техническое обслуживание</title>
<link rel="stylesheet" href="/assets/css/system.css">
</head>
<body class="system-error system-error--maintenance">
<div class="icon"><?= \App\Core\Icon::render('tool', 64) ?></div>
<h1><?= htmlspecialchars($siteName, ENT_QUOTES) ?></h1>
<p><?= htmlspecialchars($message, ENT_QUOTES) ?></p>
</body>
</html>
