<?php

declare(strict_types=1);

use App\Core\Video;

test('Video: извлекает id из разных форматов YouTube', function () {
    assert_same('dQw4w9WgXcQ', Video::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    assert_same('dQw4w9WgXcQ', Video::youtubeId('https://youtu.be/dQw4w9WgXcQ'));
    assert_same('dQw4w9WgXcQ', Video::youtubeId('https://www.youtube.com/embed/dQw4w9WgXcQ'));
    assert_same('dQw4w9WgXcQ', Video::youtubeId('https://youtube.com/shorts/dQw4w9WgXcQ'));
    assert_same('s_lKTkRGKc8', Video::youtubeId('https://www.youtube.com/watch?v=s_lKTkRGKc8'));
});

test('Video: не-YouTube и мусор -> null', function () {
    assert_same(null, Video::youtubeId('https://vimeo.com/12345'));
    assert_same(null, Video::youtubeId('не ссылка'));
    assert_same(null, Video::youtubeId(''));
    assert_false(Video::isYoutube('https://example.com'));
});

test('Video: обложка и embed используют id', function () {
    $id = 'dQw4w9WgXcQ';
    assert_contains($id, Video::youtubeThumbnail($id));
    assert_contains('hqdefault', Video::youtubeThumbnail($id));
    assert_contains($id, Video::youtubeEmbed($id));
    assert_contains('nocookie', Video::youtubeEmbed($id));
    assert_contains('rel=0', Video::youtubeEmbed($id));
    assert_contains('controls=0', Video::youtubeEmbed($id));
    assert_contains('enablejsapi=1', Video::youtubeEmbed($id));
});
