<?php

/*
 * Роутер для встроенного сервера PHP (только локальная разработка):
 *   cd public && php -S 127.0.0.1:8000 router.php
 * Статические файлы отдаются как есть, остальные запросы идут в index.php.
 * В продакшене не используется — там Apache/nginx с DocumentRoot=public.
 */

$uri = urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // отдать статический файл
}
require __DIR__ . '/index.php';
