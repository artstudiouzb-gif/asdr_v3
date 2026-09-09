<?php

declare(strict_types=1);

/**
 * Классы, к которым обращаются вьюхи, должны существовать. Опечатка в
 * пространстве имён (`App\Core\Setting` вместо `App\Models\Setting`) не видна
 * ни при линте, ни в тестах: вьюха роняет страницу только когда её откроют.
 * PHPStan это ловит, но он запускается в CI, а не под рукой у разработчика.
 */

test('Вьюхи ссылаются только на существующие классы', function () {
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app/Views'));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/\\\\(App\\\\[A-Za-z0-9_\\\\]+)::/', $code, $matches) === 0) {
            continue;
        }
        foreach (array_unique($matches[1]) as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                $missing[] = str_replace(APP_ROOT . '/', '', $file->getPathname()) . ' → ' . $class;
            }
        }
    }

    assert_same([], $missing, 'вьюха обращается к несуществующему классу');
});
