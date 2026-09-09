<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\EmbedSource;

/** @param array<string, mixed> $input */
function embed_block_html(array $input, int $id = 71): string
{
    return BlockRenderer::render([
        'id' => $id,
        'type' => 'embed',
        'data' => json_encode(BlockFieldSchema::normalize('embed', $input, 'ru'), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ])['html'];
}

test('Врезка: источник опознаётся по ссылке, а не выбирается полем', function (): void {
    // Ролик — на домене без кук: посетителю не заводят профиль до просмотра.
    $yt = EmbedSource::parse('https://youtu.be/aqz-KE-bpKQ');
    assert_same('youtube', $yt['provider']);
    assert_contains('youtube-nocookie.com/embed/aqz-KE-bpKQ', $yt['src']);
    assert_same($yt['src'], EmbedSource::parse('https://www.youtube.com/watch?v=aqz-KE-bpKQ')['src']);

    // Пост канала: официальный виджет требует чужой скрипт на странице,
    // iframe с ?embed=1 отдаёт тот же пост и обходится без него.
    $tg = EmbedSource::parse('https://t.me/durov/342');
    assert_same('telegram', $tg['provider']);
    assert_same('https://t.me/durov/342?embed=1', $tg['src']);

    $form = EmbedSource::parse('https://docs.google.com/forms/d/e/1FAIpQ/viewform?usp=sf_link');
    assert_same('google_form', $form['provider']);
    assert_same('https://docs.google.com/forms/d/e/1FAIpQ/viewform?embedded=true', $form['src']);
});

test('Врезка: набор источников закрытый — чужой домен не встраивается', function (): void {
    // frame-src в CSP пропустил бы любой https, но произвольный iframe это
    // чужой код на нашей странице. Такая дверь открыта только супер-админу
    // через блок «HTML», и в обход она открываться не должна.
    foreach ([
        'https://example.com/widget',
        'https://evil.example/t.me/durov/342',
        'https://docs.google.com/document/d/1/edit',
        'javascript:alert(1)',
        "https://youtu.be/aqz-KE-bpKQ\nX",
        '',
    ] as $url) {
        assert_same(null, EmbedSource::parse($url), 'опознан чужой адрес: ' . $url);
    }
});

test('Врезка: неопознанная ссылка молчит на сайте и объясняется редактору', function (): void {
    $html = embed_block_html(['url' => 'https://example.com/widget', 'title_field' => 'Врезка']);

    // Посетителю сообщение редактору не адресовано, а пустая рамка выглядит
    // поломкой: блок считается пустым и на страницу не попадает.
    assert_not_contains('<iframe', $html);
    assert_not_contains('block-embed__frame', $html);
    assert_true(\App\Core\BlockRenderer::isVisuallyEmpty($html));

    // Причину редактор узнаёт в форме, иначе увидел бы пустое место без объяснения.
    $hints = \App\Core\BlockHints::forBlock('embed', ['url' => 'https://example.com/widget']);
    assert_same(1, count($hints));
    assert_contains('не распознана', $hints[0]);

    // Рабочая ссылка подсказку не вызывает.
    assert_same([], \App\Core\BlockHints::forBlock('embed', ['url' => 'https://youtu.be/aqz-KE-bpKQ']));
});

test('Врезка: рамка названа для диктора, высота уходит в scoped CSS', function (): void {
    $out = BlockRenderer::render([
        'id' => 72,
        'type' => 'embed',
        'data' => json_encode(BlockFieldSchema::normalize('embed', [
            'title_field' => 'Запись заседания',
            'url' => 'https://t.me/durov/342',
            'ratio' => 'fixed',
            'height' => '480',
            'caption' => 'Канал Агентства',
        ], 'ru'), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    // Без имени диктор объявляет «фрейм» и ничего больше.
    assert_contains('title="Запись заседания"', $out['html']);
    assert_contains('loading="lazy"', $out['html']);
    assert_contains('block-embed__frame--telegram', $out['html']);
    assert_contains('block-embed__frame--ratio-fixed', $out['html']);
    // Инлайн-стили в блоках запрещены тестами: высота — переменной.
    assert_contains('--embed-height:480px', $out['css']);
    assert_not_contains('style=', $out['html']);
    assert_contains('Канал Агентства', $out['html']);
});

test('Врезка: у каждой пропорции есть правило', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/embed.css');

    foreach (['16-9', '4-3', '1-1', 'fixed'] as $ratio) {
        assert_contains('.block-embed__frame--ratio-' . $ratio, $css);
    }
    foreach (EmbedSource::PROVIDERS as $provider) {
        assert_true(
            in_array($provider, ['youtube', 'telegram', 'google_form'], true),
            'новый источник обязан получить решение о доверии: ' . $provider
        );
    }
});
