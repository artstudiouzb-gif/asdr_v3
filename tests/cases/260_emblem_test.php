<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Emblem;
use App\Core\DesignSettings;
use App\Core\Uploader;
use App\Models\Setting;

// Регрессия: загруженная фирменная эмблема сохранялась, но на сайте не
// появлялась. Эмблема работает CSS-маской, а маске нужен viewBox: без него
// SVG нечем масштабировать. Второй тихий случай — файл, который не разобрался
// при загрузке: Uploader кладёт на диск заглушку 1×1, и знак пропадает.

test('SVG без viewBox и размеров не годится трафаретом, и это сказано словами', function () {
    $bad = Emblem::checkSvg('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10H0z"/></svg>');
    assert_false($bad['ok']);
    assert_contains('viewBox', $bad['error']);

    $good = Emblem::checkSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path d="M0 0h10v10H0z"/></svg>');
    assert_true($good['ok'], 'с viewBox файл принимается');

    $broken = Emblem::checkSvg('<html><body>не svg</body></html>');
    assert_false($broken['ok']);

    // Заглушка, которой Uploader заменяет неразобранный файл, узнаётся отдельно.
    $stub = Emblem::checkSvg('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>');
    assert_false($stub['ok']);
    assert_contains('пустой файл', $stub['error']);
});

test('Загрузка SVG достраивает viewBox из размеров и переживает BOM', function () {
    $out = Uploader::sanitizeSvgString('<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><path d="M0 0h10v10H0z"/></svg>');
    assert_contains('viewBox="0 0 48 48"', $out, 'без viewBox маска не отрисуется');
    assert_true(Emblem::checkSvg($out)['ok']);

    $withBom = "\xEF\xBB\xBF" . '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h10v10H0z"/></svg>';
    $cleaned = Uploader::sanitizeSvgString($withBom);
    assert_contains('viewBox="0 0 24 24"', $cleaned, 'BOM не должен превращать файл в пустую заглушку');
    assert_true(Emblem::checkSvg($cleaned)['ok']);

    // Опасное содержимое по-прежнему вырезается.
    $dirty = Uploader::sanitizeSvgString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><script>alert(1)</script><path d="M0 0h8v8H0z" onclick="x()"/></svg>');
    assert_not_contains('<script', $dirty);
    assert_not_contains('onclick', $dirty);
});

test('Адрес эмблемы кодируется, а не отбрасывается', function () {
    assert_same('/uploads/public/gerb%20%281%29.svg', Emblem::cssUrl('/uploads/public/gerb (1).svg'));
    assert_not_contains('"', Emblem::cssUrl('/uploads/public/a"b.svg'));

    $theme = (string) file_get_contents(APP_ROOT . '/app/Core/SiteThemeCss.php');
    assert_contains('Emblem::cssUrl($emblem)', $theme, 'тема подставляет адрес через общий кодировщик');
});

test('Негодная эмблема не сохраняется молча (БД)', function () {
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        skip_test('TEST_DB_* не заданы');
        return;
    }
    reset_design_state();

    $dir = rtrim((string) Config::get('paths.public_uploads'), '/');
    $urlBase = rtrim((string) Config::get('paths.public_uploads_url'), '/');
    $badName = 'emblem-test-bad-' . substr(md5((string) mt_rand()), 0, 8) . '.svg';
    $goodName = 'emblem-test-ok-' . substr(md5((string) mt_rand()), 0, 8) . '.svg';
    file_put_contents($dir . '/' . $badName, '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10H0z"/></svg>');
    file_put_contents($dir . '/' . $goodName, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path d="M0 0h10v10H0z"/></svg>');

    $post = $_POST;
    try {
        $_POST = ['emblem' => $urlBase . '/' . $goodName];
        $warnings = DesignSettings::save($_POST);
        assert_same([], $warnings);
        assert_same($urlBase . '/' . $goodName, (string) Setting::get('design_emblem', ''));

        $_POST = ['emblem' => $urlBase . '/' . $badName];
        $warnings = DesignSettings::save($_POST);
        assert_true($warnings !== [], 'редактор получает объяснение, а не тихое «сохранено»');
        assert_same(
            $urlBase . '/' . $goodName,
            (string) Setting::get('design_emblem', ''),
            'прежняя рабочая эмблема остаётся вместо негодной'
        );

        $_POST = ['emblem' => ''];
        DesignSettings::save($_POST);
        assert_same('', (string) Setting::get('design_emblem', ''), 'очистка поля возвращает встроенный знак');
    } finally {
        $_POST = $post;
        @unlink($dir . '/' . $badName);
        @unlink($dir . '/' . $goodName);
        Setting::set('design_emblem', '');
        reset_design_state();
    }
});

test('Знак в шапке не гасится глобальным правилом', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    assert_contains('.site-header__logo:not(:has(img))::before', $css, 'знак выводится вместо логотипа-картинки');

    // Регрессия: правило «запрет псевдоэлементов логотипа» гасило ::before
    // вместе с ::after, и эмблема не появлялась в шапке ни при каком файле —
    // ни своя из «Дизайна», ни встроенная.
    foreach (explode('}', $css) as $rule) {
        $parts = explode('{', $rule, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$selector, $body] = $parts;
        if (!str_contains($selector, '.site-header__logo::before')) {
            continue;
        }
        if (!str_contains($body, 'display: none') && !str_contains($body, 'display:none')
            && !str_contains($body, 'content: none') && !str_contains($body, 'content:none')) {
            continue;
        }
        assert_true(
            str_contains($selector, ':has(img)'),
            'скрывать знак можно только при загруженном логотипе, селектор: ' . trim($selector)
        );
    }
});

test('Водяной знак карточки-акта не выключен вариантами оформления', function () {
    // Регрессия: варианты «feature-card» и «editorial» гасили .act-card__emblem
    // через display:none, и фирменный слой пропадал со всех страниц с актами.
    foreach ([
        '/public/assets/css/gov-theme.css',
        '/public/assets/css/public-editorial-pages.css',
    ] as $file) {
        $css = (string) file_get_contents(APP_ROOT . $file);
        foreach (explode('}', $css) as $rule) {
            $parts = explode('{', $rule, 2);
            if (count($parts) !== 2 || !str_contains($parts[0], '.act-card__emblem')) {
                continue;
            }
            assert_not_contains('display: none', $parts[1], 'знак прячут в ' . $file . ': ' . trim($parts[0]));
            assert_not_contains('display:none', $parts[1], 'знак прячут в ' . $file . ': ' . trim($parts[0]));
        }
    }
});
