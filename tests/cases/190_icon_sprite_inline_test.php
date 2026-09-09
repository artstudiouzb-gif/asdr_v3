<?php

declare(strict_types=1);

use App\Core\Icon;
use App\Core\Media;

/**
 * Иконки встраиваются в страницу, а не тянутся спрайтом на 2 МБ; изображения
 * несут собственные размеры; заголовки альбомов не ломают порядок уровней.
 */

test('Иконки: render ссылается на символ внутри документа', function () {
    $svg = Icon::render('home', 20);

    assert_contains('href="#tabler-home"', $svg);
    assert_not_contains('tabler-sprite.svg', $svg, 'страница не должна грузить спрайт целиком');
});

test('Иконки: injectSprite встраивает геометрию использованных символов', function () {
    $html = '<html><body class="x">' . Icon::render('home', 20) . Icon::render('search', 20) . '</body></html>';
    $out = Icon::injectSprite($html);

    assert_contains('<svg class="tabler-sprite"', $out);
    assert_contains('<symbol id="tabler-home"', $out);
    assert_contains('<symbol id="tabler-search"', $out);
    // Спрайт идёт сразу за <body>, чтобы ссылки резолвились при первом рендере.
    assert_true(
        strpos($out, '<svg class="tabler-sprite"') < strpos($out, 'href="#tabler-home"'),
        'спрайт должен стоять до первой ссылки на символ'
    );
    // Неиспользуемые иконки в страницу не попадают.
    assert_not_contains('<symbol id="tabler-abacus"', $out);
});

test('Иконки: геометрия для вставляемых скриптом иконок есть всегда', function () {
    $out = Icon::injectSprite('<html><body>' . Icon::render('home', 20) . '</body></html>');

    foreach (Icon::RUNTIME_ICONS as $name) {
        assert_contains('<symbol id="tabler-' . $name . '"', $out, "нет геометрии для {$name}");
    }
});

test('Иконки: список RUNTIME_ICONS совпадает с вызовами во фронтенд-скрипте', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/frontend.js');
    preg_match_all("/asdrIcon\('([a-z0-9-]+)'/", $js, $matches);

    $used = array_values(array_unique($matches[1] ?? []));
    sort($used);
    $declared = Icon::RUNTIME_ICONS;
    sort($declared);

    assert_same($used, $declared, 'RUNTIME_ICONS разошёлся с frontend.js — иконки после клика будут пустыми');
});

test('Иконки: индекс спрайта существует и указывает на реальные символы', function () {
    $index = require dirname(__DIR__, 2) . '/app/Core/data/tabler-sprite-index.php';
    assert_true(is_array($index) && count($index) > 4000, 'индекс спрайта пуст или неполон');

    $sprite = dirname(__DIR__, 2) . '/public' . Icon::SPRITE_PATH;
    $handle = fopen($sprite, 'rb');
    assert_true($handle !== false, 'спрайт не найден');

    foreach (['home', 'search', 'x'] as $name) {
        [$offset, $length] = $index[$name];
        fseek($handle, $offset);
        $chunk = (string) fread($handle, $length);
        assert_contains('<symbol id="tabler-' . $name . '"', $chunk);
        assert_contains('</symbol>', $chunk);
    }
    fclose($handle);
});

test('Изображения: собственные размеры проставляются локальным файлам', function () {
    $existing = glob(dirname(__DIR__, 2) . '/public/uploads/public/*.jpg') ?: [];
    if ($existing === []) {
        skip_test('нет локальных изображений для проверки');
    }
    $url = '/uploads/public/' . basename($existing[0]);

    $dimensions = Media::dimensions($url);
    assert_true($dimensions !== null && $dimensions[0] > 0 && $dimensions[1] > 0, 'размеры не определились');

    $html = Media::picture($url, 'Тест');
    assert_contains('width="' . $dimensions[0] . '" height="' . $dimensions[1] . '"', $html);
});

test('Изображения: внешние адреса и SVG остаются без размеров', function () {
    assert_same(null, Media::dimensions('https://cdn.example.com/photo.jpg'));
    assert_same(null, Media::dimensions('//cdn.example.com/photo.jpg'));
    assert_same(null, Media::dimensions('/uploads/public/no-such-file.jpg'));
});

test('Альбомы: заголовок карточки — h2, порядок уровней не рвётся', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/albums.php');
    assert_contains('<h1>', $view);
    assert_contains('<h2 class="album-card__title">', $view);
    assert_not_contains('<h3 class="album-card__title">', $view);

    // Внешний вид держится классом, а не уровнем заголовка. Само правило живёт
    // в теме: копия в базовом файле была мёртвой (тема подключается позже и
    // задавала те же свойства заново), поэтому проверяем действующее место.
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/gov-theme.css');
    assert_contains('.album-card__title { font-size:', $css);
});
