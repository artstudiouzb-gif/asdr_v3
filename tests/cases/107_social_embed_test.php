<?php

use App\Core\SocialEmbed;

test("SocialEmbed::transform преобразует ссылки Telegram, Instagram, Facebook и YouTube в интерактивные блоки", function () {
    // 1. Telegram посты
    $tg = "<p>https://t.me/artstudio_uz/123</p>";
    $outTg = SocialEmbed::transform($tg);
    assert_contains("social-embed--telegram", $outTg);
    assert_contains("data-telegram-post=\"artstudio_uz/123\"", $outTg);

    // 2. Instagram посты и Reels
    $ig = "<p>https://www.instagram.com/p/C_abc123/</p>";
    $outIg = SocialEmbed::transform($ig);
    assert_contains("social-embed--instagram", $outIg);
    assert_contains("Смотреть в Instagram", $outIg);

    // 3. YouTube видео
    $yt = "<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>";
    $outYt = SocialEmbed::transform($yt);
    assert_contains("social-embed--youtube", $outYt);
    assert_contains("youtube-nocookie.com/embed/dQw4w9WgXcQ", $outYt);
    $outShort = SocialEmbed::transform('<p>https://www.youtube.com/shorts/dQw4w9WgXcQ</p>');
    assert_contains('youtube-nocookie.com/embed/dQw4w9WgXcQ', $outShort, 'YouTube Shorts использует тот же безопасный плеер');

    // 4. Facebook — privacy-first карточка без обязательного внешнего SDK.
    $fb = '<p><a href="https://www.facebook.com/example/posts/123">https://www.facebook.com/example/posts/123</a></p>';
    $outFb = SocialEmbed::transform($fb);
    assert_contains('social-embed--facebook', $outFb);
    assert_contains('social-embed-card--facebook', $outFb);
});
