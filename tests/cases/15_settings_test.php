<?php

declare(strict_types=1);

use App\Core\Analytics;
use App\Core\Database;
use App\Core\SettingsValidator;
use App\Models\Setting;

test('Setting: getLocalized возвращает языковой вариант при наличии и базовый откат', function () {
    Setting::set('site_name', 'Базовый сайт');
    Setting::set('site_name_uz', 'Oʻzbekiston portali');

    assert_same('Oʻzbekiston portali', Setting::getLocalized('site_name', 'uz'));
    assert_same('Базовый сайт', Setting::getLocalized('site_name', 'ru'));
    assert_same('Базовый сайт', Setting::getLocalized('site_name', 'en'));
});

test('SettingsValidator: GA ID только формата G-XXXX', function () {
    assert_same('G-ABCD1234', SettingsValidator::gaId('g-abcd1234'));
    assert_same('', SettingsValidator::gaId('<script>alert(1)</script>'));
    assert_same('', SettingsValidator::gaId('UA-12345'));
});

test('SettingsValidator: Метрика — только цифры', function () {
    assert_same('12345678', SettingsValidator::ymId('12345678'));
    assert_same('', SettingsValidator::ymId('12ab'));
    assert_same('', SettingsValidator::ymId('<script>'));
});

test('SettingsValidator: HEX-цвет и дефолт', function () {
    assert_same('#1a2b3c', SettingsValidator::hexColor('#1A2B3C'));
    assert_same('#1a1a1a', SettingsValidator::hexColor('red', '#1a1a1a'));
    assert_same('#1a1a1a', SettingsValidator::hexColor('#xyz', '#1a1a1a'));
});

test('SettingsValidator: короткое имя PWA обрезается до 12 и чистит теги', function () {
    assert_same('Очень длинно', SettingsValidator::shortName('Очень длинное имя'));
    assert_same('safe', SettingsValidator::shortName("sa<>fe"));
    assert_same(12, mb_strlen(SettingsValidator::shortName(str_repeat('x', 30))));
});

test('SettingsValidator: неотрицательное целое', function () {
    assert_same(30, SettingsValidator::nonNegativeInt('30'));
    assert_same(0, SettingsValidator::nonNegativeInt('-5', 0));
    assert_same(0, SettingsValidator::nonNegativeInt('abc', 0));
});

test('SettingsValidator: текст, email и SMTP-поля нормализуются строго', function () {
    assert_same('Первая строка Вторая', SettingsValidator::plainText("  Первая строка\nВторая  ", 40));
    assert_same('12345', SettingsValidator::plainText('123456789', 5));

    assert_same('', SettingsValidator::optionalEmail(''));
    assert_same('admin@example.uz', SettingsValidator::optionalEmail(' admin@example.uz '));
    assert_true(SettingsValidator::optionalEmail('admin@') === null);

    assert_same(587, SettingsValidator::port(''));
    assert_same(465, SettingsValidator::port('465'));
    assert_true(SettingsValidator::port('0') === null);
    assert_true(SettingsValidator::port('70000') === null);
    assert_true(SettingsValidator::port('587/tcp') === null);

    assert_same('smtp.example.uz', SettingsValidator::smtpHost(' SMTP.Example.UZ '));
    assert_same('127.0.0.1', SettingsValidator::smtpHost('127.0.0.1'));
    assert_true(SettingsValidator::smtpHost('https://smtp.example.uz:587') === null);
    assert_true(SettingsValidator::smtpHost('bad host') === null);
});

test('SettingsValidator: диапазоны и публичный CDN URL проверяются без тихой подмены', function () {
    assert_same(60, SettingsValidator::boundedInt('', 0, 3600, 60));
    assert_same(0, SettingsValidator::boundedInt('0', 0, 3600, 60));
    assert_same(3600, SettingsValidator::boundedInt('3600', 0, 3600, 60));
    assert_true(SettingsValidator::boundedInt('-1', 0, 3600, 60) === null);
    assert_true(SettingsValidator::boundedInt('3601', 0, 3600, 60) === null);

    assert_same('https://cdn.example.uz/assets', SettingsValidator::publicBaseUrl(' https://cdn.example.uz/assets/ '));
    assert_same('', SettingsValidator::publicBaseUrl(''));
    assert_true(SettingsValidator::publicBaseUrl('https://user:pass@cdn.example.uz') === null);
    assert_true(SettingsValidator::publicBaseUrl('https://cdn.example.uz/?v=1') === null);
    assert_true(SettingsValidator::publicBaseUrl('javascript:alert(1)') === null);
});

test('Analytics: скрипт строится из ID без сырого JS', function () {
    $s = Analytics::buildScript('G-TEST1234', '99887766');
    assert_contains('G-TEST1234', $s);
    assert_contains('googletagmanager', $s);
    assert_contains('99887766', $s);
    assert_contains('mc.yandex.ru', $s);
    // Мусорный ввод не попадает в скрипт (валидируется).
    $safe = Analytics::buildScript('"><script>evil()</script>', 'x');
    assert_not_contains('evil', $safe);
    assert_same('', $safe);
});
