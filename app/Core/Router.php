<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database;
use App\Models\Language;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable|array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        // Менеджер редиректов: старые URL (переезд с прежнего сайта) уводим на
        // новые адреса до сопоставления маршрутов — работает и для «занятых»
        // путей. Только GET/HEAD, панель не редиректится.
        if (in_array(strtoupper($method), ['GET', 'HEAD'], true)
            && !str_starts_with($path, '/admin')
            && Database::isConnected()) {
            try {
                $redirect = \App\Models\Redirect::findByPath($path);
                if ($redirect !== null) {
                    \App\Models\Redirect::recordHit((int) $redirect['id']);
                    $target = \App\Models\Redirect::buildTarget(
                        (string) $redirect['to_url'],
                        (string) ($_SERVER['QUERY_STRING'] ?? '')
                    );
                    http_response_code((int) $redirect['code'] === 302 ? 302 : 301);
                    header('Location: ' . $target);
                    return;
                }
            } catch (\Throwable) {
                // Таблицы может не быть до применения миграции — не мешаем сайту.
            }
        }

        $path = $this->resolveLocale($path, $method, $uri);
        if ($path === null) {
            return;
        }

        // HEAD обслуживается маршрутами GET. Регистрировать их отдельно не
        // нужно: HTTP требует, чтобы HEAD отвечал теми же заголовками, что и
        // GET, а тело отбрасывает сам веб-сервер. Пока сравнение было строгим,
        // HEAD не совпадал ни с одним маршрутом и получал 404 на **каждой**
        // странице — то есть мониторинг доступности, ходящий HEAD'ом (так
        // делают почти все, он дешевле), считал сайт лежащим. Что поддержку
        // писали, видно по остальному коду: и кэш ответов, и менеджер
        // редиректов, и разрешение языка отдельно проверяют GET/HEAD.
        $routeMethod = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $routeMethod) {
                continue;
            }

            $regex = $this->compile($route['pattern']);
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, static fn ($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
                $this->invoke($route['handler'], $params);
                return;
            }
        }

        http_response_code(404);
        View::render('errors/404');
    }

    /**
     * Определяет язык из первого сегмента URL. Если это код активного
     * не-дефолтного языка (site.com/uz/...), язык устанавливается и префикс
     * отрезается. Админка (/admin) под языковой префикс не попадает.
     */
    private function resolveLocale(string $path, string $method, string $uri): ?string
    {
        // В режиме установки (или при недоступной БД) языков ещё нет.
        if (!Database::isConnected()) {
            Locale::set('ru');
            Locale::setPath($path);

            return $path;
        }

        if (!LocalePreference::managesPath($path)) {
            $activeCodes = Language::activeCodes();
            $requestedCode = LocalePreference::requestedCode($uri, $activeCodes);
            if ($requestedCode !== null) {
                Locale::set($requestedCode);
            } else {
                $user = Auth::user();
                if ($user && !empty($user['admin_lang']) && in_array($user['admin_lang'], $activeCodes, true)) {
                    Locale::set((string) $user['admin_lang']);
                } else {
                    Locale::set(Language::defaultCode());
                }
            }
            Locale::setPath($path);

            return $path;
        }

        $activeCodes = Language::activeCodes();
        $defaultCode = Language::defaultCode();

        // Языковой префикс не создаёт второго адреса служебным областям.
        // `managesPath()` выше смотрит на путь запроса, и `/uz/admin/...` под
        // её исключения не подпадает: префикс срезался, и запрос доезжал до
        // настоящего обработчика `/admin/...`. Все проверки доступа, которые
        // стоят ДО роутера (скрытый вход `AdminEntryGate`, онбординг второго
        // фактора в public/index.php), сверяют путь запроса — и такой адрес
        // проходил мимо них. Проверять срезанный путь надо здесь, в
        // единственном месте, где префикс снимается: список проверок до
        // роутера ещё вырастет, и каждая из них повторяла бы эту ошибку.
        // Локализованной версии у служебной области нет, поэтому отдаём путь
        // как есть — ни один маршрут с ним не совпадёт, и ответом будет 404.
        $strippedPath = $this->withoutLanguagePrefix($path, $activeCodes);
        if ($strippedPath !== $path && !LocalePreference::managesPath($strippedPath)) {
            Locale::set($defaultCode);
            Locale::setPath($path);

            return $path;
        }

        $requestedCode = LocalePreference::requestedCode($uri, $activeCodes);
        if ($requestedCode !== null) {
            LocalePreference::remember($requestedCode);
            $contentPath = $this->withoutLanguagePrefix($path, $activeCodes);
            $target = Locale::url($contentPath, $requestedCode) . LocalePreference::querySuffix($uri);
            header('Cache-Control: private, no-store');
            header('Location: ' . $target, true, 302);

            return null;
        }

        $storedCode = LocalePreference::storedCode($_COOKIE, $activeCodes);
        if (preg_match('#^/([a-zA-Z]{2,8})(/.*|)$#', $path, $m)) {
            $code = strtolower($m[1]);
            if ($code !== $defaultCode && in_array($code, $activeCodes, true)) {
                $rest = $m[2] === '' ? '/' : $m[2];
                // Сохранённый язык перебивает адрес только на корне сайта.
                // На конкретной странице выигрывает язык из URL: иначе ссылка
                // на /uz/news/x, присланная человеку с русской настройкой,
                // уводила бы его на /news/x, а ответ каждой страницы зависел
                // бы от cookie и не мог кэшироваться на CDN.
                if ($storedCode !== null && $storedCode !== $code && $rest === '/') {
                    if (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
                        $target = Locale::url($rest, $storedCode) . LocalePreference::querySuffix($uri);
                        header('Cache-Control: private, no-store');
                        header('Location: ' . $target, true, 302);

                        return null;
                    }

                    Locale::set($storedCode);
                    Locale::setPath($rest);

                    return $rest;
                }
                if ($storedCode === null) {
                    LocalePreference::remember($code);
                }
                Locale::set($code);
                Locale::setPath($rest);

                return $rest;
            }
        }

        // То же правило для адресов основного языка (без префикса): на корень
        // сохранённый язык действует, на конкретную страницу — нет.
        if ($storedCode !== null && $storedCode !== $defaultCode && $path === '/') {
            if (in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
                $target = Locale::url($path, $storedCode) . LocalePreference::querySuffix($uri);
                header('Cache-Control: private, no-store');
                header('Location: ' . $target, true, 302);

                return null;
            }

            Locale::set($storedCode);
            Locale::setPath($path);

            return $path;
        }

        Locale::set($defaultCode);
        Locale::setPath($path);

        return $path;
    }

    /** @param string[] $activeCodes */
    private function withoutLanguagePrefix(string $path, array $activeCodes): string
    {
        if (!preg_match('#^/([a-zA-Z]{2,8})(/.*|)$#', $path, $matches)) {
            return $path;
        }

        $code = strtolower($matches[1]);
        if (!in_array($code, $activeCodes, true)) {
            return $path;
        }

        return $matches[2] === '' ? '/' : $matches[2];
    }

    private function compile(string $pattern): string
    {
        $pattern = rtrim($pattern, '/');
        if ($pattern === '') {
            $pattern = '/';
        }

        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);

        return '#^' . $regex . '$#u';
    }

    private function invoke(callable|array $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $controller->$method($params);
            return;
        }

        $handler($params);
    }
}
