<?php

declare(strict_types=1);

use App\Core\A11ySettings;
use App\Core\UzCyrillic;
use App\Core\View;
use App\Models\Setting;

/**
 * Узбекскую кириллицу можно выключить («Языки» → «Узбекская кириллица»).
 * Выключенная не переводит страницу и тем, у кого кириллица осталась в
 * cookie: из этого режима переключателю было бы уже нечем вывести.
 */
test('Кириллица: выключенная настройка не переводит страницу даже по cookie', function () {
    $beforeCookie = $_COOKIE[A11ySettings::COOKIE] ?? null;
    $beforeLang = \App\Core\Locale::current();

    try {
        \App\Core\Locale::set('uz');
        $_COOKIE[A11ySettings::COOKIE] = A11ySettings::toCookie(['script' => 'cyrl'] + A11ySettings::DEFAULTS);

        Setting::overrideInMemory(UzCyrillic::SETTING, '1');
        assert_true(UzCyrillic::enabled());
        assert_true(View::wantsCyrillic());

        Setting::overrideInMemory(UzCyrillic::SETTING, '0');
        assert_false(UzCyrillic::enabled());
        assert_false(View::wantsCyrillic(), 'выключенная кириллица не должна переводить страницу');
    } finally {
        Setting::forgetCache();
        \App\Core\Locale::set($beforeLang);
        if ($beforeCookie === null) {
            unset($_COOKIE[A11ySettings::COOKIE]);
        } else {
            $_COOKIE[A11ySettings::COOKIE] = $beforeCookie;
        }
    }
});

test('Кириллица: без настройки включена (вид сайта не меняется)', function () {
    Setting::forgetCache();
    assert_true(UzCyrillic::enabled());
});
