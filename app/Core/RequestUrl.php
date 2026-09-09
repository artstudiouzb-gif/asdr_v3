<?php

declare(strict_types=1);

namespace App\Core;

/** Безопасное определение внешней схемы/host за reverse proxy. */
final class RequestUrl
{
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $forwardedRaw = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($forwardedRaw === '') {
            return false;
        }

        // Forwarded-заголовки контролируются клиентом, если origin доступен
        // напрямую. Доверяем схеме только когда непосредственный peer входит
        // в явный allowlist reverse proxy. После ClientIp::applyTrustedProxy()
        // исходный peer сохраняется в ASDR_PROXY_ADDR.
        $peer = trim((string) ($_SERVER['ASDR_PROXY_ADDR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        if ($peer === '' || !ClientIp::isTrustedProxy($peer)) {
            return false;
        }

        // Reverse proxy может передать цепочку значений; внешняя схема — первая.
        $forwarded = strtolower(trim(explode(',', $forwardedRaw)[0]));
        return $forwarded === 'https';
    }

    /**
     * Куда увести запрос, пришедший по HTTP, — или null, если уводить некуда.
     *
     * Принудительный HTTPS выводится из `app.url`, а не из отдельной настройки
     * и не из правила в `.htaccess`. Причина в том, чем оборачивается ошибка.
     * Безусловное правило в `.htaccess` уводит на https и установку, которая
     * сертификата ещё не получила: сайт становится недоступен целиком, и
     * починить его можно только по FTP. Отдельная настройка была бы третьим
     * местом, где записано одно и то же, и разъехалась бы с остальными.
     *
     * `app.url` про это уже знает: боевой адрес обязателен по чек-листу
     * выпуска, а `release_check.php` требует у него схему https. Значит,
     * `https://` в нём — и есть объявленное владельцем «сайт работает по
     * HTTPS», и включать защиту по нему безопасно: пока адрес остаётся
     * `http://`, ничего не меняется и ничего не ломается.
     *
     * Без этого сайт, где шаг «включить HTTPS» на хостинге пропущен или
     * потерян при переезде, продолжает отвечать по HTTP молча: сессионная
     * cookie уходит без флага Secure (`Session::start()` берёт его у
     * `isHttps()`), а HSTS не отправляется вовсе — он ставится только на
     * HTTPS-ответе, то есть браузер никогда не узнает, что надо повышать
     * схему сам.
     */
    public static function httpsRedirectTarget(): ?string
    {
        // Про CLI здесь не спрашиваем: обёртка в bootstrap уже вышла на нём
        // раньше, а без этой проверки метод остаётся чистым и проверяемым.
        if (self::isHttps()) {
            return null;
        }

        // Схему берём из сырой настройки, а не из AppUrl::base(): тот
        // повышает http до https по текущему запросу, а нам нужно именно
        // объявленное значение.
        $configured = trim((string) Config::get('app.url', ''));
        $parts = parse_url($configured);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ($parts['host'] ?? '') === ''
        ) {
            return null;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // Проверка Let's Encrypt по HTTP-01 читается ровно по http://; увести
        // её на https значит не дать выписать сертификат, ради которого
        // редирект и ставится.
        if (str_starts_with($path, '/.well-known/acme-challenge/')) {
            return null;
        }

        // Адрес запроса управляется клиентом и уходит в заголовок Location.
        // Перевод строки там — это подстановка чужих заголовков.
        if ($uri === '' || preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            $uri = '/';
        }

        $host = (string) $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $authority = $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

        // Хост берём из настройки, а не из HTTP_HOST: заголовок подделывается,
        // и редирект стал бы открытым.
        return 'https://' . $authority . $uri;
    }

    public static function origin(): string
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:]+\])(?::[0-9]{1,5})?$/i', $host)) {
            $host = 'localhost';
        }

        return (self::isHttps() ? 'https' : 'http') . '://' . $host;
    }
}
