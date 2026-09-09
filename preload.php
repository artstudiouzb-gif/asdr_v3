<?php

declare(strict_types=1);

/**
 * OPcache Preloader for ASDR v2.
 *
 * Precompiles core framework classes, models, and controllers into shared memory
 * during PHP-FPM startup, reducing per-request overhead and latency.
 *
 * Configure in php.ini:
 *   opcache.preload=/path/to/asdr_v2/preload.php
 *   opcache.preload_user=www-data
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

// 1. Register PSR-4 autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// 2. Load global helpers
if (is_file(APP_ROOT . '/app/Core/helpers.php')) {
    require_once APP_ROOT . '/app/Core/helpers.php';
}

if (!function_exists('preload_directory')) {
    /**
     * Helper to recursively scan and preload PHP files in a directory.
     *
     * @param string $dir
     * @param list<string> $excludes
     * @return list<string> list of successfully compiled/loaded file paths
     */
    function preload_directory(string $dir, array $excludes = []): array
    {
        $preloaded = [];
        if (!is_dir($dir)) {
            return $preloaded;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $hasOpcache = function_exists('opcache_compile_file')
            && (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
                || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            $skip = false;
            foreach ($excludes as $exclude) {
                if (str_contains($path, $exclude)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            try {
                if ($hasOpcache) {
                    if (@opcache_compile_file($path)) {
                        $preloaded[] = $path;
                        continue;
                    }
                }

                // Derive class name from path: /app/Core/Router.php -> App\Core\Router
                $appPos = strpos($path, '/app/');
                if ($appPos !== false) {
                    $subPath = substr($path, $appPos + 5, -4); // strip '/app/' and '.php'
                    $class = 'App\\' . str_replace('/', '\\', $subPath);
                    if (class_exists($class, true) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false)) {
                        $preloaded[] = $path;
                        continue;
                    }
                }

                require_once $path;
                $preloaded[] = $path;
            } catch (\Throwable) {
                // Skip unresolvable files without failing the master process
            }
        }

        return $preloaded;
    }
}

// 3. Preload Core classes, Models, and Controllers
$excludes = [
    '/app/Core/bootstrap.php',
    '/app/Core/data/',
    '/app/Core/lang/',
    '/app/Views/',
    '/templates/',
    '/tests/',
];

$preloadedFiles = array_merge(
    preload_directory(APP_ROOT . '/app/Core', $excludes),
    preload_directory(APP_ROOT . '/app/Models', $excludes),
    preload_directory(APP_ROOT . '/app/Controllers', $excludes)
);

return $preloadedFiles;
