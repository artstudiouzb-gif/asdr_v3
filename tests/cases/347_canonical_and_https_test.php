<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\RequestUrl;
use App\Core\SeoHelper;

/**
 * Canonical и принудительный HTTPS: два места, где неверная настройка не
 * ломает страницу, а тихо портит результат — поэтому проверяются измерением.
 */

test('Canonical несёт значащие параметры адреса и отбрасывает мусорные', function (): void {
    $base = 'https://example.com';
    $path = '/news';
    $url = static fn (string $query): string => SeoHelper::canonicalUrl($base, $path, $query);

    // Без параметров — как было.
    assert_same('https://example.com/news', $url(''), 'пустая query не должна добавлять «?»');

    // Пагинация и рубрика — это разное содержимое, и оно обязано попасть в
    // canonical: иначе страница объявляет себя дублем первой.
    assert_same('https://example.com/news?page=7', $url('page=7'));
    assert_same('https://example.com/news?category=obrazovanie', $url('category=obrazovanie'));
    assert_same('https://example.com/news?mtab=photo&mpage=2', $url('mtab=photo&mpage=2'));
    assert_same('https://example.com/news?m=2026-09', $url('m=2026-09'));

    // Первая страница списка и голый адрес — одна и та же страница.
    assert_same('https://example.com/news', $url('page=1'), 'page=1 не отличается от адреса без параметра');
    assert_same('https://example.com/news', $url('mpage=1'));
    assert_same('https://example.com/news', $url('page='), 'пустое значение параметром не является');

    // Метки кампаний, свободный поиск и служебные параметры содержимое не
    // меняют — в canonical им места нет, иначе адресов станет бесконечно.
    assert_same('https://example.com/news?page=3', $url('utm_source=fb&fbclid=abc&page=3'));
    assert_same('https://example.com/news', $url('q=%D1%82%D0%B5%D1%81%D1%82'));
    assert_same('https://example.com/news?page=2', $url('token=secret&page=2'));

    // Сортировка — те же записи в другом порядке, то есть дубль.
    assert_same('https://example.com/news?page=2', $url('sort=title&page=2'), 'sort не попадает в canonical');

    // Порядок параметров нормализуется: иначе один набор записей получил бы
    // два разных canonical и сам стал бы дублем.
    assert_same($url('page=2&category=x'), $url('category=x&page=2'), 'порядок параметров не меняет canonical');

    // Массив в параметре подделывается формой; http_build_query развернул бы
    // его в page%5B0%5D=1.
    assert_not_contains('%5B', $url('page[]=1'));
});

test('Шапка сайта берёт canonical из SeoHelper, а не из одного пути', function (): void {
    $header = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/_header.php');
    assert_contains('SeoHelper::canonicalUrl(', $header, 'canonical собирается одним местом');
    assert_not_contains(
        '$canonicalUrl = $appUrl . Locale::url(Locale::path());',
        $header,
        'прежний путь-без-параметров объявлял пагинацию и рубрики дублями'
    );
});

test('Принудительный HTTPS включается объявленным боевым адресом', function (): void {
    $server = $_SERVER;
    // Config::set() заменяет конфигурацию целиком, поэтому правим только свой
    // ключ через merge() и возвращаем прежний блок «app» в конце: иначе
    // соседние тесты остались бы без настроек (файлы сортируются строкой, и
    // после 347_* идут 35_*, 98_*, 99_*).
    $appConfig = (array) Config::get('app', []);
    $useUrl = static function (string $url) use ($appConfig): void {
        Config::merge(['app' => ['url' => $url] + $appConfig]);
    };

    // Запрос по HTTP: ни HTTPS, ни порта 443, ни доверенного прокси.
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['ASDR_PROXY_ADDR']);
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_HOST'] = 'evil.example';
    $_SERVER['REQUEST_URI'] = '/admin/login?next=1';

    // Сайт ещё не на HTTPS — редиректа быть не должно, иначе установка без
    // сертификата становится недоступной целиком.
    $useUrl('http://artstudio.uz');
    assert_same(null, RequestUrl::httpsRedirectTarget(), 'http-адрес не включает принуждение');

    $useUrl('');
    assert_same(null, RequestUrl::httpsRedirectTarget(), 'без APP_URL принуждать не по чему');

    // Объявлен боевой https — уводим, сохраняя путь и параметры.
    $useUrl('https://artstudio.uz');
    assert_same(
        'https://artstudio.uz/admin/login?next=1',
        RequestUrl::httpsRedirectTarget(),
        'путь и query сохраняются'
    );

    // Хост берётся из настройки, а не из HTTP_HOST: иначе подделанный
    // заголовок превращает редирект в открытый.
    assert_not_contains('evil.example', (string) RequestUrl::httpsRedirectTarget());

    // Проверка Let's Encrypt по HTTP-01 читается только по http://.
    $_SERVER['REQUEST_URI'] = '/.well-known/acme-challenge/abc123';
    assert_same(null, RequestUrl::httpsRedirectTarget(), 'ACME-проверку уводить нельзя');

    // Перевод строки в адресе — это подстановка чужих заголовков в Location.
    $_SERVER['REQUEST_URI'] = "/news\r\nSet-Cookie: a=b";
    assert_same('https://artstudio.uz/', RequestUrl::httpsRedirectTarget(), 'управляющие символы срезаются');

    // Уже по HTTPS — уводить некуда, иначе получится петля.
    $_SERVER['REQUEST_URI'] = '/news';
    $_SERVER['HTTPS'] = 'on';
    assert_same(null, RequestUrl::httpsRedirectTarget(), 'на HTTPS редиректа быть не должно');

    $_SERVER = $server;
    Config::merge(['app' => $appConfig]);
});

test('Редирект на HTTPS стоит до подключения к БД и сохраняет метод', function (): void {
    $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/bootstrap.php');
    assert_contains('RequestUrl::httpsRedirectTarget()', $bootstrap);
    assert_contains('308', $bootstrap, 'POST обязан повториться тем же методом, а не превратиться в GET');

    // Порядок важен: незашифрованный запрос не должен доходить до данных.
    $redirectAt = strpos($bootstrap, 'httpsRedirectTarget()');
    $databaseAt = strpos($bootstrap, 'Database::init(');
    assert_true(is_int($redirectAt) && is_int($databaseAt) && $redirectAt < $databaseAt, 'редирект раньше Database::init');
});

test('release_check проверяет поведение сайта, а не только строку APP_URL', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/release_check.php');
    assert_contains('https_redirect', $script, 'схема в APP_URL — намерение, а не факт');
    assert_contains('CURLINFO_REDIRECT_URL', $script, 'нужен Location, иначе 301 на чужой адрес пройдёт за успех');
});
