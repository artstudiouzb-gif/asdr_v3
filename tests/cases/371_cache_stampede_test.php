<?php

declare(strict_types=1);

use App\Core\Cache;

/**
 * Поведение файлового кэша под одновременной нагрузкой.
 *
 * Блокировка на сборке в проекте была и раньше: после сброса кэша значение
 * считает первый пришедший, остальные ждут. Беда была в том, **чего они
 * ждали**: истечение TTL не удаляет файл, готовая копия всё это время лежит
 * на диске, — а ожидающие вместо неё усыпляли себя на три секунды. То есть в
 * момент, когда страница нужнее всего (популярная, срок только что вышел),
 * посетитель получал не «страницу секундной давности», а секунды ожидания.
 *
 * Второе — сам момент пересборки. Обычный TTL назначает её всем сразу: срок
 * вышел, и первый же наплыв упирается в неё целиком. Упреждающий пересчёт
 * (XFetch) размазывает этот момент: чем ближе срок и чем дороже сборка, тем
 * вероятнее, что кто-то один пересоберёт значение заранее — по ещё свежей
 * копии и не задерживая никого.
 */

/** Путь файла кэша тем же методом, каким его считает сам класс. */
function cache_path_of(string $key): string
{
    $method = (new ReflectionClass(Cache::class))->getMethod('pathFor');

    return (string) $method->invoke(null, $key);
}

function cache_should_refresh_early(string $key, int $ttl): bool
{
    $method = (new ReflectionClass(Cache::class))->getMethod('shouldRefreshEarly');

    return (bool) $method->invoke(null, $key, $ttl);
}

test('Пока идёт чужая сборка, ожидающий получает копию, а не паузу', function (): void {
    $key = 'test_stampede:waiter';
    Cache::forget($key);
    Cache::put($key, 'вчерашняя сборка');

    // Копия просрочена по TTL, но лежит на диске: истечение срока файл не
    // удаляет. Память запроса при этом надо сбросить — иначе тест проверял бы
    // L1-кэш, а не поведение под блокировкой.
    $path = cache_path_of($key);
    touch($path, time() - 600);
    clearstatcache(true, $path);
    (new ReflectionClass(Cache::class))->getProperty('memoryCache')->setValue(null, []);

    // Занимаем ту же блокировку, что берёт генератор. Блокировки flock(2)
    // принадлежат открытому файлу, а не процессу, поэтому второй попытке из
    // того же процесса будет отказано — ровно как чужому запросу.
    $lock = fopen($path . '.lock', 'c');
    assert_true($lock !== false, 'файл блокировки открыт');
    assert_true(flock($lock, LOCK_EX | LOCK_NB), 'блокировка занята');

    $rebuilt = false;
    $startedAt = microtime(true);
    $value = Cache::remember($key, static function () use (&$rebuilt): string {
        $rebuilt = true;

        return 'новая сборка';
    }, 60);
    $elapsed = microtime(true) - $startedAt;

    flock($lock, LOCK_UN);
    fclose($lock);

    assert_same('вчерашняя сборка', $value, 'отдана готовая копия');
    assert_false($rebuilt, 'вторую сборку того же значения никто не запускал');
    assert_true($elapsed < 1.0, 'ожидание вместо копии: ' . round($elapsed, 2) . ' с');

    Cache::forget($key);
});

test('Без замеренной стоимости и без TTL упреждающего пересчёта нет', function (): void {
    $key = 'test_stampede:noearly';
    Cache::forget($key);
    Cache::put($key, 'значение');

    // TTL нулевой — значение живёт до правки контента, упреждать нечего.
    assert_false(cache_should_refresh_early($key, 0));
    // TTL есть, но стоимость сборки ещё не замерена (первый раз значение
    // положили мимо remember): упреждение без неё было бы гаданием.
    assert_false(cache_should_refresh_early($key, 600));

    Cache::forget($key);
});

test('Чем ближе срок, тем вероятнее упреждающий пересчёт', function (): void {
    $key = 'test_stampede:xfetch';
    Cache::forget($key);

    $ttl = 100;
    $built = 0;
    Cache::remember($key, static function () use (&$built): string {
        $built++;
        // Заметная стоимость сборки: упреждение считается по ней.
        usleep(20_000);

        return 'значение';
    }, $ttl);
    assert_same(1, $built, 'первый заход собирает значение сам');

    $path = cache_path_of($key);
    assert_true(is_file($path . '.meta'), 'стоимость сборки записана рядом со значением');
    // Дорогая сборка: доля от TTL ограничена сверху десятой частью, иначе
    // значение пересобиралось бы почти на каждом запросе.
    file_put_contents($path . '.meta', '1000');

    // Случайность здесь настоящая (mt_rand), поэтому сравниваем два состояния
    // на одном и том же посеве: результат воспроизводим, а не «обычно так».
    $draws = static function (int $age) use ($key, $path, $ttl): int {
        touch($path, time() - $age);
        clearstatcache(true, $path);
        mt_srand(20260910);
        $hits = 0;
        for ($i = 0; $i < 200; $i++) {
            $hits += cache_should_refresh_early($key, $ttl) ? 1 : 0;
        }

        return $hits;
    };

    // Значение только что записано — до срока целый TTL, пересобирать рано.
    assert_same(0, $draws(0), 'сразу после записи никто не пересобирает');
    // До срока осталась сотая часть — большинство запросов вызовется собрать.
    $near = $draws($ttl - 1);
    assert_true($near > 100, 'у самого срока упреждение редкое: ' . $near . ' из 200');

    Cache::forget($key);
    assert_false(is_file($path . '.meta'), 'замер удаляется вместе со значением');
});

test('Неизменное значение не переписывается на диске', function (): void {
    $key = 'test_stampede:unchanged';
    Cache::forget($key);

    assert_true(Cache::putIfChanged($key, '<html>страница</html>'), 'первая запись состоялась');
    assert_false(Cache::putIfChanged($key, '<html>страница</html>'), 'то же содержимое не переписывается');
    assert_true(Cache::putIfChanged($key, '<html>другая</html>'), 'изменение записывается');
    assert_same('<html>другая</html>', Cache::get($key));

    $path = cache_path_of($key);
    Cache::forget($key);
    assert_false(is_file($path . '.sig'), 'отпечаток удаляется вместе со значением');
});
