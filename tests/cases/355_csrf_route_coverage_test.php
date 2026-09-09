<?php

declare(strict_types=1);

/**
 * Каждый POST-маршрут обязан проверять CSRF.
 *
 * Проверка стоит в каждом обработчике по отдельности — общего middleware в
 * роутере нет, — и это ровно тот случай, когда защита теряется молча: забытый
 * `Csrf::verifyRequest()` ничего не ломает, форма работает, и узнают о нём не
 * при ревью, а при подделке запроса. Точечные проверки в наборе были (тест 160
 * сверяет несколько контроллеров построчно), сплошной — не было.
 *
 * Правило: тело обработчика либо само зовёт `Csrf::`, либо делегирует
 * помощнику того же класса, который зовёт. Публичные ручки, которым токен
 * взять неоткуда, перечислены поимённо — список закрытый, и каждая запись
 * обязана объяснить, чем защищена вместо токена.
 */

/** Тело метода класса: от сигнатуры до парной закрывающей скобки. */
function php_method_body(string $source, string $method): ?string
{
    if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $brace = strpos($source, '{', (int) $m[0][1]);
    if ($brace === false) {
        return null;
    }

    $depth = 0;
    $len = strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $brace, $i - $brace + 1);
            }
        }
    }

    return null;
}

test('Все POST-маршруты проверяют CSRF', function (): void {
    $index = (string) file_get_contents(APP_ROOT . '/public/index.php');

    // Маршруты объявлены псевдонимами классов — собираем карту из use.
    $alias = [];
    if (preg_match_all('/use\s+([\\\\A-Za-z_]+)(?:\s+as\s+(\w+))?\s*;/', $index, $uses, PREG_SET_ORDER) > 0) {
        foreach ($uses as $use) {
            $full = ltrim($use[1], '\\');
            $short = $use[2] ?? substr($full, (int) strrpos($full, '\\') + 1);
            $alias[$short] = $full;
        }
    }

    preg_match_all(
        "/->post\(\s*'([^']+)'\s*,\s*\[?([\\\\A-Za-z_]+)(?:::class)?\s*,\s*'(\w+)'/",
        $index,
        $routes,
        PREG_SET_ORDER
    );
    assert_true(count($routes) > 150, 'POST-маршруты не разобраны (найдено ' . count($routes) . ')');

    /*
     * Ручки, у которых токена быть не может, и чем они защищены вместо него.
     * Список закрытый: новая строка здесь — это решение о безопасности,
     * которое обязано быть объяснено в самом обработчике.
     */
    $exempt = [
        // sendBeacon при закрытии вкладки: сессии у посетителя нет и заводить
        // её нельзя (сломает кеш публичных ответов). Защита — закрытый список
        // имён метрик, приведение значений к числу и ограничитель частоты.
        '/_vitals' => 'Core Web Vitals: beacon без сессии, защищён FileRateLimiter',
    ];

    $unprotected = [];
    foreach ($routes as [, $path, $class, $method]) {
        if (isset($exempt[$path])) {
            continue;
        }

        $full = ltrim($alias[$class] ?? $class, '\\');
        $file = APP_ROOT . '/app/' . str_replace(['App\\', '\\'], ['', '/'], $full) . '.php';
        if (!is_file($file)) {
            $unprotected[] = $path . ' → файл контроллера не найден (' . $full . ')';
            continue;
        }

        $source = (string) file_get_contents($file);
        $body = php_method_body($source, $method);
        if ($body === null) {
            $unprotected[] = $path . ' → метод ' . $method . '() не найден';
            continue;
        }

        if (str_contains($body, 'Csrf::')) {
            continue;
        }

        // Делегирование помощнику того же класса: `$this->allowMutation()`
        // у ручек подписки на push проверяет токен из заголовка.
        $viaHelper = false;
        if (preg_match_all('/\$this->(\w+)\s*\(/', $body, $calls) > 0) {
            foreach (array_unique($calls[1]) as $helper) {
                $helperBody = php_method_body($source, $helper);
                if ($helperBody !== null && str_contains($helperBody, 'Csrf::')) {
                    $viaHelper = true;
                    break;
                }
            }
        }

        if (!$viaHelper) {
            $unprotected[] = $path . ' → ' . $class . '::' . $method . '()';
        }
    }

    assert_same(
        [],
        $unprotected,
        "POST-маршруты без проверки CSRF:\n      " . implode("\n      ", $unprotected)
    );
});

test('Исключения из CSRF объяснены в самом обработчике', function (): void {
    // Строка в списке исключений теста ничего не стоит. Объяснение обязано
    // лежать рядом с кодом, иначе следующий читатель обработчика не узнает,
    // что отсутствие токена здесь — решение, а не забывчивость.
    $vitals = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/VitalsController.php');

    assert_contains('без CSRF', $vitals, 'обработчик не объясняет, почему обходится без токена');
    assert_contains('FileRateLimiter', $vitals, 'у ручки без токена обязан быть ограничитель частоты');
});
