<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Uploader;

test('Reveal: тип анимации попадает в data-reveal-type, обратная совместимость с bool (группа 4.2)', function () {
    // Новый формат {enabled, type}.
    $out = BlockRenderer::render([
        'id' => 1, 'type' => 'text', 'custom_css' => null,
        'data' => json_encode(['content' => 'x', '_reveal' => ['enabled' => true, 'type' => 'slide-up']]),
    ])['html'];
    assert_contains('data-reveal', $out);
    assert_contains('data-reveal-type="slide-up"', $out);

    // Старое булево true → трактуется как fade.
    $legacy = BlockRenderer::render([
        'id' => 2, 'type' => 'text', 'custom_css' => null,
        'data' => json_encode(['content' => 'x', '_reveal' => true]),
    ])['html'];
    assert_contains('data-reveal-type="fade"', $legacy);

    // Выключено → атрибута нет.
    $off = BlockRenderer::render([
        'id' => 3, 'type' => 'text', 'custom_css' => null,
        'data' => json_encode(['content' => 'x', '_reveal' => ['enabled' => false, 'type' => 'fade']]),
    ])['html'];
    assert_true(!str_contains($off, 'data-reveal'), 'выключенная анимация не даёт data-reveal');
});

test('SVG-иконка: sanitizeSvgString вырезает <script> (группа 4.3)', function () {
    $dirty = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24">'
        . '<script>alert(1)</script><circle cx="12" cy="12" r="10"/></svg>';
    $clean = Uploader::sanitizeSvgString($dirty);

    assert_true(!str_contains($clean, '<script'), 'скрипт должен быть вырезан');
    assert_contains('<circle', $clean, 'безопасное содержимое сохраняется');
});

test('SVG-санитайзер: XXE-сущности не разворачиваются, DOCTYPE вырезается', function () {
    $secret = tempnam(sys_get_temp_dir(), 'xxe');
    file_put_contents($secret, 'DB_PASSWORD=supersecret');

    $dirty = '<?xml version="1.0"?>'
        . '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
        . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';
    $clean = Uploader::sanitizeSvgString($dirty);
    @unlink($secret);

    assert_true(!str_contains($clean, 'supersecret'), 'содержимое локального файла не должно попасть в SVG');
    assert_true(!str_contains($clean, '<!DOCTYPE'), 'DOCTYPE должен быть вырезан');

    // Без сущностей документ после вырезания DOCTYPE остаётся валидным.
    $plain = '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
        . '<svg xmlns="http://www.w3.org/2000/svg"><rect width="5" height="5"/></svg>';
    assert_contains('<rect', Uploader::sanitizeSvgString($plain), 'легитимный SVG с DOCTYPE сохраняет содержимое');
});

test('Блок advantages рендерит ключ локальной Tabler-иконки', function () {
    $html = render_block('advantages', [
        'title' => 'Плюсы',
        'items' => [['icon_svg' => 'bolt', 'title' => 'Скорость', 'text' => 'быстро']],
    ]);
    assert_contains('block-advantages__icon--svg', $html);
    assert_contains('#tabler-bolt', $html);
    assert_not_contains('<rect', $html, 'собственная SVG-геометрия не хранится в данных блока');
});
