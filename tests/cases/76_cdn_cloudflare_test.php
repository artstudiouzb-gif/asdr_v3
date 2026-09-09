<?php

declare(strict_types=1);

use App\Core\Asset;
use App\Core\Cloudflare;
use App\Core\Database;
use App\Models\Setting;

// CDN для загрузок (pull-zone, без переноса домена) и интеграция Cloudflare.

test('Asset::rewriteMedia переписывает /uploads/public на CDN, не трогая абсолютные URL', function () {
    if (!Database::isConnected()) {
        return; // cdnBase читает настройку из БД
    }
    $prev = Setting::get('perf_cdn_url', '');
    Setting::set('perf_cdn_url', 'https://cdn.example.net');
    // сбросить статический кэш cdnBase
    (function () { $r = new \ReflectionClass(Asset::class); $p = $r->getProperty('cdnBase'); $p->setValue(null, null); })();

    $html = '<img src="/uploads/public/a.jpg">'
        . "<div style=\"background-image:url('/uploads/public/b.jpg')\"></div>"
        . '<meta property="og:image" content="https://site.uz/uploads/public/c.jpg">';
    $out = Asset::rewriteMedia($html);

    assert_true(str_contains($out, 'src="https://cdn.example.net/uploads/public/a.jpg"'), 'src переписан на CDN');
    assert_true(str_contains($out, "url('https://cdn.example.net/uploads/public/b.jpg')"), 'css url переписан');
    assert_true(str_contains($out, 'content="https://site.uz/uploads/public/c.jpg"'), 'абсолютный og:image не тронут');

    Setting::set('perf_cdn_url', (string) $prev);
    (function () { $r = new \ReflectionClass(Asset::class); $p = $r->getProperty('cdnBase'); $p->setValue(null, null); })();
});

test('Cloudflare::enabled требует включения, токена и зоны', function () {
    if (!Database::isConnected()) {
        return;
    }
    $pe = Setting::get('cf_enabled', '0');
    $pt = Setting::get('cf_api_token', '');
    $pz = Setting::get('cf_zone_id', '');

    Setting::set('cf_enabled', '0');
    Setting::set('cf_api_token', 'tok');
    Setting::set('cf_zone_id', 'zone');
    assert_true(!Cloudflare::enabled(), 'выключено — не активно');

    Setting::set('cf_enabled', '1');
    Setting::set('cf_api_token', '');
    assert_true(!Cloudflare::enabled(), 'без токена — не активно');

    Setting::set('cf_api_token', 'tok');
    Setting::set('cf_zone_id', '');
    assert_true(!Cloudflare::enabled(), 'без зоны — не активно');

    Setting::set('cf_zone_id', 'zone');
    assert_true(Cloudflare::enabled(), 'всё задано — активно');

    // purgeSite не должна бросать исключений (сеть не дергаем — verify отдельно).
    Setting::set('cf_enabled', '0');
    Cloudflare::purgeSite();

    Setting::set('cf_enabled', (string) $pe);
    Setting::set('cf_api_token', (string) $pt);
    Setting::set('cf_zone_id', (string) $pz);
});
