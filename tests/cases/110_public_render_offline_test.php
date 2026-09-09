<?php

declare(strict_types=1);

/*
 * Рендер публичной страницы не ходит в сеть.
 *
 * Правило появилось не из принципа, а из замера. Плашку курсов валют собирала
 * шапка, и на промахе кэша делался блокирующий запрос к cbu.uz: страница
 * отвечала 733 мс вместо 37 мс. Хуже того, неудача не запоминалась — пока
 * источник лежит, полный таймаут платил каждый посетитель и каждый запрос
 * заново, то есть чужая авария становилась нашей.
 *
 * Сама плашка убрана, но правило осталось: доступность стороннего сервиса не
 * должна становиться доступностью сайта. Ходить наружу можно оттуда, где
 * ждать позволено, — из воркера по cron или из админки.
 */

test('Публичные вьюхи и контроллеры не делают исходящих запросов', function (): void {
    $roots = [
        APP_ROOT . '/app/Views/site',
        APP_ROOT . '/app/Controllers/Site',
        APP_ROOT . '/templates',
    ];

    // Вызовы, каждый из которых означает поход в сеть на пути рендера.
    $forbidden = ['Http::get', 'Http::getSafeRemote', 'Http::post', 'curl_exec', 'file_get_contents(\'http'];

    $found = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $needle) {
                if (str_contains($code, $needle)) {
                    $found[] = str_replace(APP_ROOT . '/', '', $file->getPathname()) . ': ' . $needle;
                }
            }
        }
    }

    assert_same(
        [],
        $found,
        'на пути рендера появился поход в сеть: ' . implode(', ', $found)
            . '. Переносите его в воркер по cron или в админку — там ожидание допустимо.'
    );
});
