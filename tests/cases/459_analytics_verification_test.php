<?php

declare(strict_types=1);

use App\Core\Analytics;
use App\Core\SecurityHeaders;
use App\Core\SettingsValidator;
use App\Models\Setting;

// Подтверждение прав в Search Console / Вебмастере мета-тегом и Вебвизор
// Метрики. Коды и флаги приходят из настроек, но в разметку и CSP попадает
// только проверенное значение.

test('verificationToken: принимает код и целиком вставленный мета-тег', function () {
    assert_same('abcDEF_123-xyz', SettingsValidator::verificationToken(' abcDEF_123-xyz '));
    assert_same('3f2a1b0c9d8e7f60', SettingsValidator::verificationToken('<meta name="yandex-verification" content="3f2a1b0c9d8e7f60" />'));
    assert_same('Tok_en-1234', SettingsValidator::verificationToken("<meta name='google-site-verification' content='Tok_en-1234'>"));
    assert_same('', SettingsValidator::verificationToken('"><script>alert(1)</script>'));
    assert_same('', SettingsValidator::verificationToken('<meta content="a b c d e f">'));
    assert_same('', SettingsValidator::verificationToken('abc'), 'слишком короткий код');
});

test('Analytics: Вебвизор включается флагом в init Метрики', function () {
    assert_not_contains('webvisor', Analytics::buildScript('', '12345678'));
    assert_contains("ym(12345678,'init',{webvisor:true,clickmap:true", Analytics::buildScript('', '12345678', true));
    assert_same('', Analytics::buildScript('', 'x', true), 'без валидного номера Вебвизора нет');
});

test('Analytics: tag.js Метрики грузится со своим id', function () {
    $s = Analytics::buildScript('', '114397823');
    assert_contains("'https://mc.yandex.ru/metrika/tag.js?id='+114397823", $s);
    assert_not_contains("tag.js','ym'", $s, 'адрес без id отдаёт сборку, не подхватывающую очередь');
});

test('publicCsp: Метрика получает mc.yandex.com, Вебвизор — WebSocket', function () {
    $plain = SecurityHeaders::publicCsp('n', ['ym' => true]);
    assert_contains('https://mc.yandex.com', $plain);
    assert_not_contains('wss://', $plain);
    $wv = SecurityHeaders::publicCsp('n', ['ym' => true, 'ym_webvisor' => true]);
    assert_contains("connect-src 'self' https://mc.yandex.ru https://mc.yandex.com wss://mc.yandex.ru wss://mc.yandex.com;", $wv);
    $none = SecurityHeaders::publicCsp('n', ['ym_webvisor' => true]);
    assert_not_contains('wss://', $none, 'без счётчика Вебвизору открывать нечего');
});

test('Analytics::verificationTags: имена мета-тегов и чистка мусора', function () {
    ensure_test_db();
    Setting::set('verify_google', '<meta name="google-site-verification" content="G00gle_Tok-en" />');
    Setting::set('verify_yandex', '"><b>');
    Setting::set('verify_bing', '0123456789ABCDEF0123456789ABCDEF');
    $tags = Analytics::verificationTags();
    assert_same('G00gle_Tok-en', $tags['google-site-verification'] ?? null);
    assert_true(!isset($tags['yandex-verification']), 'мусор не выводится');
    assert_same('0123456789ABCDEF0123456789ABCDEF', $tags['msvalidate.01'] ?? null);
});

test('publicCsp: Метрика получает yastatic, blob и встраивание в кабинет', function () {
    $ym = SecurityHeaders::publicCsp('n', ['ym' => true]);
    assert_contains('https://yastatic.net', $ym);
    assert_contains('https://yastatic.net https://mc.yandex.com', $ym, 'скрипты Метрики грузятся и с mc.yandex.com');
    assert_contains('frame-src https: blob:;', $ym);
    assert_contains("worker-src 'self' blob:;", $ym);
    assert_contains("frame-ancestors 'self' https://metrika.yandex.ru", $ym);
    $none = SecurityHeaders::publicCsp('n', []);
    assert_not_contains('yastatic', $none);
    assert_contains("frame-ancestors 'self'", $none);
    assert_not_contains('metrika.yandex', $none, 'без счётчика встраивать некому');
});

test('httpsRedirectTarget: www-зеркало уводится на основной адрес и по HTTPS', function () {
    $server = $_SERVER;
    $appConfig = (array) \App\Core\Config::get('app', []);
    \App\Core\Config::merge(['app' => ['url' => 'https://asdr.example'] + $appConfig]);
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_URI'] = '/news?page=2';
    try {
        $_SERVER['HTTP_HOST'] = 'www.asdr.example';
        assert_same('https://asdr.example/news?page=2', \App\Core\RequestUrl::httpsRedirectTarget());
        $_SERVER['HTTP_HOST'] = 'WWW.asdr.example:443';
        assert_same('https://asdr.example/news?page=2', \App\Core\RequestUrl::httpsRedirectTarget());
        $_SERVER['HTTP_HOST'] = 'asdr.example';
        assert_same(null, \App\Core\RequestUrl::httpsRedirectTarget(), 'основной адрес — без петли');
        $_SERVER['HTTP_HOST'] = 'staging.asdr.example';
        assert_same(null, \App\Core\RequestUrl::httpsRedirectTarget(), 'чужой хост не трогаем');
    } finally {
        $_SERVER = $server;
        \App\Core\Config::merge(['app' => $appConfig]);
    }
});
