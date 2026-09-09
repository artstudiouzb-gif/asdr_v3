<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\BlockData\HeroBlockNormalizer;

// Hero: фон видео/YouTube/фото, overlay с цветом и прозрачностью, позиция
// текста и подложка под текстом.

function render_hero(array $data): string
{
    $rendered = BlockRenderer::render(['id' => 1, 'type' => 'hero', 'data' => json_encode($data), 'custom_css' => null]);

    return $rendered['html'] . "\n" . $rendered['css'];
}

test('Hero: YouTube-фон рендерит iframe с nocookie-доменом и id', function () {
    $html = render_hero([
        'title' => 'Заголовок',
        'bg_type' => 'youtube',
        'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]);
    assert_true(str_contains($html, 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'iframe YouTube с id');
    assert_true(str_contains($html, 'block-hero--video'), 'класс видео-героя');
    assert_true(str_contains($html, 'autoplay=1&amp;mute=1&amp;loop=1'), 'автозапуск без звука, цикл');
    assert_contains('playlist=dQw4w9WgXcQ', $html, 'playlist нужен YouTube для бесшовного loop');
    assert_contains('controls=0', $html, 'стандартные элементы управления отключены');
    assert_contains('enablejsapi=1', $html, 'фон можно возобновить после системной паузы');
    assert_contains('origin=http%3A%2F%2Flocalhost', $html, 'JS API ограничен origin сайта');
    assert_contains('data-hero-youtube-background', $html);
    assert_contains('data-src="https://www.youtube-nocookie.com/', $html, 'iframe загружается JS только рядом с viewport');
    assert_true(str_contains($html, 'loading="lazy"'), 'third-party iframe не блокирует первый рендер');
    assert_contains('referrerpolicy="strict-origin-when-cross-origin"', $html, 'YouTube получает origin сайта для проверки embed');
    assert_not_contains('showinfo=', $html);
    assert_not_contains('modestbranding=', $html);
    assert_not_contains('rel=', $html);
    assert_not_contains('iv_load_policy=', $html);
});

test('Hero: YouTube использует изображение как poster до готовности iframe', function () {
    $html = render_hero([
        'title' => 'Заголовок',
        'bg_type' => 'youtube',
        'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'image' => '/uploads/public/hero-poster.jpg',
    ]);

    assert_contains('class="block-hero__youtube-poster media-position--center-center', $html);
    assert_contains('src="/uploads/public/hero-poster.jpg"', $html);
    assert_contains('fetchpriority="high"', $html);
});

test('Hero: режим без фона не активирует сохранённый YouTube URL', function () {
    $html = render_hero([
        'title' => 'Заголовок',
        'bg_type' => 'none',
        'youtube_url' => 'https://www.youtube.com/watch?v=s_lKTkRGKc8',
    ]);

    assert_not_contains('youtube-nocookie.com/embed/s_lKTkRGKc8', $html);
    assert_not_contains('block-hero--video', $html);
    assert_contains('block-hero--plain', $html);
});

test('Hero: выбранный MP4 рендерится как безопасный фоновый ролик', function () {
    $html = render_hero([
        'title' => 'Заголовок',
        'bg_type' => 'video',
        'video_url' => '/uploads/public/hero.mp4',
    ]);

    assert_contains('<video class="block-hero__video media-position--center-center media-position-mobile--center-center"', $html);
    assert_contains('<source src="/uploads/public/hero.mp4" type="video/mp4">', $html);
    assert_not_contains(' controls ', $html);
    assert_contains('block-hero--video', $html);
    assert_not_contains('block-hero--plain', $html);

    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/frontend.js');
    assert_contains("video.controls = false", $js);
    assert_contains("video.muted = true", $js);
    assert_contains("video.loop = true", $js);
    assert_contains("document.addEventListener('visibilitychange'", $js);
    assert_contains("command('playVideo')", $js);
    assert_contains("command('mute')", $js);
    assert_contains("command('setLoop', [true])", $js);
    assert_contains("new IntersectionObserver", $js);
    assert_contains("frame.getAttribute('data-src')", $js);
});

test('Hero: градиентное затемнение использует цвет, направление и прозрачность', function () {
    $html = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'overlay_enabled' => true,
        'overlay_mode' => 'gradient', 'overlay_color' => '#123456',
        'overlay_direction' => 'to_bottom_right', 'overlay_opacity' => 80,
    ]);
    // #123456 = rgb(18,52,86), 80% => 0.8
    assert_true(str_contains($html, '--hero-scrim-rgb:18,52,86'), 'overlay RGB из цвета');
    assert_true(str_contains($html, '--hero-scrim-a:0.8'), 'overlay alpha из прозрачности');
    assert_true(str_contains($html, '--hero-scrim-direction:135deg'), 'направление градиента');
    assert_not_contains('--hero-scrim-end-rgb', $html);
    assert_not_contains(' style="', $html, 'Динамические параметры hero публикуются как scoped CSS');
});

test('Hero: overlay поддерживает сплошную заливку без градиента', function () {
    $html = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'overlay_enabled' => true,
        'overlay_mode' => 'solid', 'overlay_color' => '#123456',
    ]);

    assert_contains('block-hero__scrim--solid', $html);

    $css = theme_css();
    assert_contains('.block-hero__scrim--solid { background: rgba(var(--hero-scrim-rgb), var(--hero-scrim-a)); }', $css);
});

test('Hero: автоматическое направление overlay следует за положением текста', function () {
    $right = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'overlay_enabled' => true,
        'overlay_direction' => 'auto', 'text_position' => 'right',
    ]);
    assert_contains('--hero-scrim-direction:270deg', $right);

    $invalid = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'overlay_enabled' => true,
        'overlay_direction' => '90deg;background:red', 'text_position' => 'center',
    ]);
    assert_contains('--hero-scrim-direction:0deg', $invalid);
    assert_not_contains('background:red', $invalid);
});

test('Hero: overlay по умолчанию не выводится и не загрязняет медиа', function () {
    $html = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
    ]);

    assert_not_contains('block-hero__scrim', $html);
    assert_not_contains('--hero-scrim-', $html);
});

test('Hero: затемнение включается явно и поддерживает два режима', function () {
    $gradient = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'overlay_enabled' => true, 'overlay_mode' => 'gradient',
        'overlay_color' => '#123456', 'overlay_opacity' => 55,
    ]);
    assert_contains('block-hero__scrim', $gradient);
    assert_contains('--hero-scrim-rgb:18,52,86', $gradient);
    assert_not_contains('block-hero__scrim--solid', $gradient);

    $solid = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'overlay_enabled' => true, 'overlay_mode' => 'solid',
        'overlay_color' => '#123456', 'overlay_direction' => 'auto',
    ]);
    assert_contains('block-hero__scrim--solid', $solid);

    $disabled = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'overlay_enabled' => false, 'overlay_color' => '#123456', 'overlay_opacity' => 55,
    ]);
    assert_not_contains('block-hero__scrim', $disabled);
});

test('Hero: форма и сохранение содержат два явных режима затемнения', function () {
    $root = dirname(__DIR__, 2);
    $form = (string) file_get_contents($root . '/app/Views/admin/pages/block_form.php');
    assert_contains('name="overlay_enabled"', $form);
    assert_contains('data-hero-visual-toggle="hero_overlay_settings"', $form);
    assert_contains('name="overlay_mode" value="solid"', $form);
    assert_contains('name="overlay_mode" value="gradient"', $form);
    assert_contains('data-hero-overlay-gradient', $form);
    assert_contains('name="overlay_direction"', $form);
    assert_not_contains('name="overlay_end_color"', $form);

    $normalizer = (string) file_get_contents($root . '/app/Core/BlockData/HeroBlockNormalizer.php');
    assert_contains("'overlay_enabled' => !empty", $normalizer);
    assert_contains("'overlay_mode' => \$overlayMode", $normalizer);
    assert_contains("'overlay_direction' => \$overlayDirection", $normalizer);

    assert_same(false, BlockRenderer::defaults()['hero']['overlay_enabled']);
    assert_same(35, BlockRenderer::defaults()['hero']['overlay_opacity']);
    assert_same('gradient', BlockRenderer::defaults()['hero']['overlay_mode']);
    assert_same('auto', BlockRenderer::defaults()['hero']['overlay_direction']);
});

test('Hero: мобильное фото сохраняет исходную непрозрачность', function () {
    $css = theme_css();

    assert_contains('.block-hero--media .block-hero__media { opacity: 1; }', $css);
    assert_not_contains('calc(var(--hero-scrim-a) * 1.31)', $css);
});

test('Hero: градиент затемнения держит плотность под текстом и не гаснет до нуля', function () {
    $css = theme_css();
    $form = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/pages/block_form.php');

    // Полная плотность у ближнего края и почти полная под текстовой колонкой.
    assert_contains('rgba(var(--hero-scrim-rgb), var(--hero-scrim-a)) 0%', $css);
    assert_contains('rgba(var(--hero-scrim-rgb), calc(var(--hero-scrim-a) * 0.92)) 46%', $css);
    // До дальнего края доходит нижний порог: на нуле заголовок ложился на
    // голый снимок и на светлой фотографии переставал читаться.
    assert_contains('rgba(var(--hero-scrim-rgb), calc(var(--hero-scrim-a) * 0.22)) 100%', $css);
    assert_not_contains('rgba(var(--hero-scrim-rgb), 0) 100%', $css);
    assert_contains('Градиентное от края', $form);
    assert_contains('От левого края', $form);
    assert_contains('От правого края', $form);
});

test('Hero: на телефоне затемнение почти равномерное, а надзаголовок читается на фото', function () {
    $css = theme_css();

    // Текст на телефоне занимает всю ширину, боковой градиент там не спасает.
    assert_contains('.block-hero__scrim:not(.block-hero__scrim--solid)', $css);
    assert_contains('rgba(var(--hero-scrim-rgb), calc(var(--hero-scrim-a) * 0.88)) 100%', $css);
    // Акцентный надзаголовок поверх снимка не добирает 4.5:1 при любой
    // разумной плотности затемнения — цветным остаётся только штрих.
    assert_contains('.block-hero--media .block-hero__eyebrow { color: var(--hero-text, #fff); }', $css);
});

test('Hero: контроллер передаёт данные формы отдельному нормализатору', function () {
    $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/BlockController.php');

    assert_contains('HeroBlockNormalizer::normalize($_POST, $locale)', $controller);
    assert_not_contains("case 'hero':\n                \$safe =", $controller);
});

test('Hero: позиция текста и подложка отражаются в разметке', function () {
    $html = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/uploads/public/x.jpg',
        'text_position' => 'center',
        'panel_enabled' => true, 'panel_color' => '#000000', 'panel_opacity' => 50,
    ]);
    assert_true(str_contains($html, 'block-hero--pos-center'), 'класс позиции текста');
    assert_true(str_contains($html, 'block-hero__text--panel'), 'класс подложки');
    assert_true(str_contains($html, 'rgba(0,0,0, 0.5)'), 'подложка rgba из цвета и прозрачности');
});

test('Hero: цвет текста и кнопок отдаются CSS-переменными', function () {
    $html = render_hero([
        'title' => 'X', 'bg_type' => 'image', 'image' => '/x.jpg',
        'text_color' => '#112233', 'button_color' => '#aabbcc',
        'button_text' => 'Кнопка', 'button_url' => '/o-nas',
    ]);
    assert_true(str_contains($html, '--hero-text:#112233'), 'переменная цвета текста');
    assert_true(str_contains($html, '--hero-btn:#aabbcc'), 'переменная цвета кнопок');
});

test('Hero: свой цвет фона под текстом — не зависящий от темы градиент', function () {
    $html = render_hero(['title' => 'X', 'bg_type' => 'none', 'bg_color' => '#123a6b', 'text_position' => 'left']);
    assert_true(str_contains($html, 'block-hero--bgcolor'), 'класс цветного фона');
    assert_true(str_contains($html, 'linear-gradient(90deg, rgba(18,58,107'), 'градиент выбранного цвета');
});

test('Hero: без выбранного типа не показывает случайно заполненное медиа', function () {
    $html = render_hero(['title' => 'X', 'image' => '/uploads/public/x.jpg']);
    assert_not_contains('block-hero--media', $html);
    assert_not_contains('/uploads/public/x.jpg', $html);
    assert_contains('block-hero--plain', $html);
});

test('Hero: небезопасная произвольная высота не попадает в style', function () {
    $html = render_hero(['title' => 'X', 'height' => 'custom', 'custom_height' => '100vh;background:red']);
    assert_true(str_contains($html, 'block-hero--h-custom'), 'режим сохраняется');
    assert_true(!str_contains($html, 'background:red'), 'CSS-инъекция отброшена');
});

test('Hero: своя ширина текста отдаётся переменной, мусор отбрасывается', function () {
    $html = render_hero(['title' => 'X', 'image' => '/uploads/public/x.jpg', 'text_width' => '50vw']);
    assert_true(str_contains($html, '--hero-text-width:50vw'), 'переменная ширины текста');

    $html = render_hero(['title' => 'X', 'text_width' => '5000px']);
    assert_true(str_contains($html, '--hero-text-width:2000px'), 'px ограничивается лимитом');

    $html = render_hero(['title' => 'X', 'text_width' => '50vw;background:red']);
    assert_true(!str_contains($html, 'background:red'), 'CSS-инъекция отброшена');
    assert_true(!str_contains($html, '--hero-text-width'), 'невалидное значение не выводится');
});
