<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('map_point: парсинг и нормализация карт Google/Яндекс при вставке кода iframe', function (): void {
    // 1. Вставка полного кода <iframe> из Google Maps
    $iframeHtml = '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3000!2d69.24!3d41.31" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy"></iframe>';
    $rendered1 = BlockRenderer::render([
        'id' => 1651,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => $iframeHtml,
            'title' => 'Наш офис',
            'load_mode' => 'immediate',
        ]),
    ]);
    assert_contains(
        'src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3000!2d69.24!3d41.31&amp;iwloc=near"',
        $rendered1['html']
    );

    // 2. Обычная ссылка на Google Maps без output=embed автоматически дополняется
    $rawGoogleUrl = 'https://maps.google.com/maps?q=41.31,69.24';
    $rendered2 = BlockRenderer::render([
        'id' => 1652,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => $rawGoogleUrl,
            'title' => 'Наш офис 2',
            'load_mode' => 'immediate',
        ]),
    ]);
    // 3. Прямая ссылка на место /maps/place/ автоматически преобразуется в чистый iframe по координатам
    $placeUrl = 'https://www.google.com/maps/place/Администрация/@41.3142794,69.2660741,20.75z/data=!4m6!3m5!1s0x38ae8b216d90b0b5:0x41ab3f624338b0e5!8m2!3d41.3142864!4d69.2657056';
    $rendered3 = BlockRenderer::render([
        'id' => 1653,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => $placeUrl,
            'title' => 'Администрация',
            'load_mode' => 'immediate',
        ]),
    ]);
    assert_contains('ll=41.3142864,69.2657056', $rendered3['html']);
    assert_contains('output=embed', $rendered3['html']);

    $blocked = BlockRenderer::render([
        'id' => 1654,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => 'https://example.com/fake-map',
            'load_mode' => 'immediate',
        ]),
    ]);
    assert_not_contains('example.com', $blocked['html']);
    assert_not_contains('<iframe', $blocked['html']);

    // 5. Короткая ссылка «Поделиться» из Яндекс Карт должна стать URL
    // встраиваемого виджета, а не открываться обычной страницей /maps/.
    $yandexShare = BlockRenderer::render([
        'id' => 1655,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => 'https://yandex.uz/maps/-/CDqQyB8D',
            'load_mode' => 'immediate',
        ]),
    ]);
    assert_contains(
        'src="https://yandex.uz/map-widget/v1/-/CDqQyB8D"',
        $yandexShare['html']
    );
    assert_not_contains('src="https://yandex.uz/maps/-/', $yandexShare['html']);

    // 6. Уже готовый widget URL сохраняется без повторного преобразования.
    $yandexWidget = BlockRenderer::render([
        'id' => 1656,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => 'https://yandex.ru/map-widget/v1/?ll=69.240562%2C41.311081&z=16',
            'load_mode' => 'immediate',
        ]),
    ]);
    assert_contains(
        'src="https://yandex.ru/map-widget/v1/?ll=69.240562%2C41.311081&amp;z=16"',
        $yandexWidget['html']
    );

    // 7. Ссылка на организацию переносит oid и параметры карты в widget URL.
    $yandexOrg = BlockRenderer::render([
        'id' => 1657,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => 'https://yandex.com/maps/org/example/123456789/?ll=69.24%2C41.31&z=17',
            'load_mode' => 'immediate',
        ]),
    ]);
    assert_contains('src="https://yandex.com/map-widget/v1/?', $yandexOrg['html']);
    assert_contains('oid=123456789', $yandexOrg['html']);
    assert_contains('ol=biz', $yandexOrg['html']);

    // 8. Размеры карты задаются на блок и отдельно на телефон.
    $sizedMap = BlockRenderer::render([
        'id' => 1658,
        'type' => 'map_point',
        'custom_css' => '',
        'data' => json_encode([
            'embed_url' => 'https://yandex.uz/map-widget/v1/-/CDqQyB8D',
            'load_mode' => 'immediate',
            'map_width' => 72,
            'map_height' => 560,
            'map_width_mobile' => 100,
            'map_height_mobile' => 380,
        ]),
    ]);
    assert_contains('#block-1658 .block-map__canvas{width:72%;max-width:100%;margin-inline:auto;height:560px;}', $sizedMap['css']);
    assert_contains('@media(max-width:720px){#block-1658 .block-map__canvas{width:100%;height:380px;}}', $sizedMap['css']);
});
