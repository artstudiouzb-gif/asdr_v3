<?php

declare(strict_types=1);

// PHPStan анализирует классы без запуска runtime bootstrap приложения.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}
