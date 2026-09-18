<?php

use App\Core\Database;
use App\Core\Locale;
use App\Core\Search;

// 404-трекер: этот шаблон — единственная общая точка всех 404 (роутер,
// страницы, новости, каталог). Запись не мешает отдаче страницы.
if (class_exists(\App\Models\NotFoundLog::class) && Database::isConnected()) {
    \App\Models\NotFoundLog::record();
}

$hasLocale = class_exists(Locale::class);
$lang = $hasLocale ? Locale::current() : 'ru';
$dbOn = Database::isConnected();

// Подписи идут через общий словарь, а не своей таблицей языков рядом: та
// знала ровно три языка, редактор переводов в админке её не видел вовсе, и
// четвёртый язык вышел бы здесь по-русски, ничего об этом не сообщив.
$title = t('Страница не найдена', $lang);
$text = t('Возможно, страница была перемещена или удалена. Попробуйте поиск или разделы ниже.', $lang);
$homeLabel = t('На главную', $lang);
$searchTitle = t('Поиск по сайту', $lang);
$searchBtn = t('Найти', $lang);
$didYou = t('Возможно, вы искали', $lang);
$latestT = t('Последние новости', $lang);
$sectionsT = t('Разделы сайта', $lang);
$homeUrl = $hasLocale ? Locale::url('/') : '/';
$searchUrl = $hasLocale ? Locale::url('search') : '/search';

// Умный разбор: из «мусорного» URL достаём догадку и ищем близкое.
$reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$seg = urldecode(basename($reqPath));
$guess = trim((string) preg_replace('/\.(html?|php)$/i', '', (string) preg_replace('/[-_]+/', ' ', $seg)));

$suggest = [];
$latest = [];
$sections = [];
if ($dbOn) {
    try {
        // Поиск по сайту — десяток запросов, и раньше он шёл на каждый промах,
        // включая сплошной поток от сканеров (/wp-login.php, /.env, битые
        // ссылки на картинки). Человеку подсказка нужна, скану — нет, и что
        // считать сканом, уже решено один раз в журнале 404: второй список
        // разъехался бы с первым.
        if (mb_strlen($guess) >= 2
            && !\App\Models\NotFoundLog::isNoise($reqPath)
            && class_exists(Search::class)) {
            $suggest = array_slice(Search::site($guess, 6), 0, 5);
        }
    } catch (\Throwable $e) {
        \App\Core\Logger::swallowed('Страница 404: не удалось подобрать похожие материалы', $e);
    }
    try {
        if (class_exists(\App\Models\News::class)) {
            foreach (\App\Models\News::published(4, 0, $lang) as $n) {
                $latest[] = ['title' => (string) $n['title'], 'url' => Locale::url('news/' . $n['slug'], $lang)];
            }
        }
    } catch (\Throwable $e) {
        \App\Core\Logger::swallowed('Страница 404: не удалось загрузить последние новости', $e);
    }
    try {
        if (class_exists(\App\Models\MenuItem::class)) {
            foreach (\App\Models\MenuItem::activeForLang($lang) as $mi) {
                if (!empty($mi['is_divider']) || (int) ($mi['parent_id'] ?? 0) !== 0) {
                    continue;
                }
                $sections[] = ['title' => (string) $mi['title'], 'url' => \App\Models\MenuItem::resolveUrl($mi, $lang)];
                if (count($sections) >= 8) {
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        \App\Core\Logger::swallowed('Страница 404: не удалось загрузить разделы меню', $e);
    }
}

$e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>404 — <?= $e($title) ?></title>
<link rel="stylesheet" href="/assets/css/system.css">
</head>
<body class="system-error system-error--404">
<div class="wrap">
    <p class="code">404</p>
    <h1><?= $e($title) ?></h1>
    <p class="lead"><?= $e($text) ?></p>

    <form class="s404" action="<?= $e($searchUrl) ?>" method="get" role="search" aria-label="<?= $e($searchTitle) ?>">
        <input type="search" name="q" value="<?= $e($guess) ?>" placeholder="<?= $e($searchTitle) ?>…" aria-label="<?= $e($searchTitle) ?>">
        <button type="submit"><?= $e($searchBtn) ?></button>
    </form>

    <?php if (!empty($suggest)): ?>
        <div class="suggest">
            <h2><?= $e($didYou) ?></h2>
            <ul>
                <?php foreach ($suggest as $s): ?>
                    <li><a href="<?= $e($s['url']) ?>"><?= $e($s['title']) ?></a> <span class="t">— <?= $e($s['type']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="grid">
        <?php if (!empty($latest)): ?>
            <div class="card">
                <h2><?= $e($latestT) ?></h2>
                <ul>
                    <?php foreach ($latest as $n): ?><li><a href="<?= $e($n['url']) ?>"><?= $e($n['title']) ?></a></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($sections)): ?>
            <div class="card">
                <h2><?= $e($sectionsT) ?></h2>
                <ul>
                    <?php foreach ($sections as $s): ?><li><a href="<?= $e($s['url']) ?>"><?= $e($s['title']) ?></a></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <p class="u-inline-8d74c20df9"><a class="home" href="<?= $e($homeUrl) ?>"><?= $e($homeLabel) ?></a></p>
</div>
</body>
</html>
