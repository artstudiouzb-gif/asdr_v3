<?php

declare(strict_types=1);

use App\Core\Cache;
use App\Core\PublicResponseCache;

/**
 * Аварийный снимок публичной страницы: пока база жива, каждая страница
 * оставляет копию на диске; когда база ляжет, отдаётся она, а не 503.
 *
 * Механизм существовал в коде, но не был подключён ни с одной стороны —
 * то есть выглядел рабочим и не работал.
 */

/** @return callable(string): string ключ снимка тем же методом, что и класс */
function stale_key_fn(): callable
{
    // С PHP 8.1 приватные члены доступны рефлексии без setAccessible().
    $method = (new ReflectionClass(PublicResponseCache::class))->getMethod('staleKey');

    return static fn (string $path): string => (string) $method->invoke(null, $path);
}

function set_snapshotable(bool $value): void
{
    $property = (new ReflectionClass(PublicResponseCache::class))->getProperty('snapshotable');
    $property->setValue(null, $value);
}

test('Снимок сохраняется только у пригодного ответа и находится по тому же ключу', function (): void {
    $key = stale_key_fn();
    Cache::forgetPrefix('stale_page:');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/o-agentstve';

    // Пригодный ответ — снимок появляется ровно под тем ключом, по которому
    // его потом будет искать отдача. Разъехавшиеся ключи означали бы, что
    // снимок пишется, но никогда не находится: отказ, заметный только в
    // аварии, то есть когда проверять поздно.
    set_snapshotable(true);
    PublicResponseCache::saveSnapshot('<html>свежая копия</html>');
    assert_same('<html>свежая копия</html>', Cache::get($key('/o-agentstve')));

    // Непригодный (персонализированный или с параметрами адреса) — нет.
    Cache::forgetPrefix('stale_page:');
    set_snapshotable(false);
    PublicResponseCache::saveSnapshot('<html>личная копия</html>');
    assert_same(null, Cache::get($key('/o-agentstve')), 'персонализированный ответ отдали бы чужому');

    // Пустой ответ снимком не является.
    set_snapshotable(true);
    PublicResponseCache::saveSnapshot('');
    assert_same(null, Cache::get($key('/o-agentstve')));

    Cache::forgetPrefix('stale_page:');
    set_snapshotable(false);
});

test('Ключ снимка зависит только от адреса и считается одинаково', function (): void {
    $key = stale_key_fn();

    assert_same($key('/news'), $key('/news'), 'ключ обязан быть детерминированным');
    assert_true($key('/news') !== $key('/projects'), 'разные страницы — разные снимки');
    // Язык уже в адресе, отдельным измерением его заводить не нужно.
    assert_true($key('/news') !== $key('/uz/news'), 'языковая версия — своя страница');
    assert_contains('stale_page:', $key('/news'), 'префикс нужен для группового сброса');
});

test('Снимки стираются вместе с кэшем страниц при правке контента', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/PublicResponseCache.php');

    // Иначе снятая с публикации новость вернулась бы на глаза во время аварии.
    assert_contains("Cache::forgetPrefix('page:');", $src);
    assert_contains("Cache::forgetPrefix('stale_page:');", $src);
});

test('Обе стороны подключены: сохранение во View, отдача до заглушки 503', function (): void {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/View.php');
    assert_contains('PublicResponseCache::saveSnapshot($html);', $view, 'снимок никто не пишет');

    $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/bootstrap.php');
    assert_contains('PublicResponseCache::tryServeStale()', $bootstrap, 'снимок никто не отдаёт');

    // Порядок: сначала пробуем копию, только потом заглушка.
    $stale = strpos($bootstrap, 'tryServeStale()');
    $plug = strpos($bootstrap, "errors/503.php");
    assert_true(is_int($stale) && is_int($plug) && $stale < $plug, 'заглушка не должна опережать копию');

    // /health отвечает 503 отдельной веткой выше: мониторинг знает правду.
    $health = strpos($bootstrap, "\$failedPath === '/health'");
    assert_true(is_int($health) && $health < $stale, '/health обязан отвечать раньше и честно');
});

test('Отдача снимка закрыта для служебных путей, параметров и небезопасных методов', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/PublicResponseCache.php');
    $body = substr($src, (int) strpos($src, 'function tryServeStale'));

    assert_contains("['GET', 'HEAD']", $body, 'снимок — ответ только на безопасный метод');
    assert_contains('isPrivatePath($path)', $body, 'админка и портал из снимка не отдаются');
    assert_contains('PHP_QUERY', str_replace('PHP_URL_QUERY', 'PHP_QUERY', $body), 'адрес с параметрами в снимок не попадал');
    // no-store: после восстановления базы никто не должен держать старую копию.
    assert_contains("Cache-Control: no-store", $body);
    assert_contains('X-Cache-Status: STALE-RECOVERED', $body, 'аварийную отдачу должно быть видно в логах');
});
