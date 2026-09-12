<?php

declare(strict_types=1);

use App\Core\BlockData\BlockDataInput;
use App\Core\BlockData\SubscribeBlockNormalizer;
use App\Core\BlockData\TestimonialsBlockNormalizer;
use App\Core\BlockRenderer;

// Варианты вёрстки и настройки у блоков, которые раньше умели только вывести
// список: отзывы, партнёры, карточки персон, подписка, текст с фото, иконки.

/**
 * @param array<string, mixed> $data
 * @return array{html: string, css: string}
 */
function variant_block(string $type, array $data, int $id = 700): array
{
    $rendered = BlockRenderer::render([
        'id' => $id,
        'type' => $type,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => (string) $rendered['html'], 'css' => (string) $rendered['css']];
}

test('Ввод блока: значение вне списка и число вне диапазона заменяются умолчанием', function () {
    assert_same('grid', BlockDataInput::enum(['v' => 'grid'], 'v', ['grid', 'carousel'], 'carousel'));
    assert_same('carousel', BlockDataInput::enum(['v' => 'мусор'], 'v', ['grid', 'carousel'], 'carousel'));
    assert_same('carousel', BlockDataInput::enum([], 'v', ['grid', 'carousel'], 'carousel'));

    assert_same(4, BlockDataInput::int(['n' => '4'], 'n', 2, 5, 3));
    assert_same(5, BlockDataInput::int(['n' => '99'], 'n', 2, 5, 3));
    assert_same(2, BlockDataInput::int(['n' => '-1'], 'n', 2, 5, 3));
    assert_same(3, BlockDataInput::int([], 'n', 2, 5, 3), 'без поля берётся умолчание');
    assert_same(3, BlockDataInput::int(['n' => ''], 'n', 2, 5, 3), 'пустая строка — не ноль');
});

test('Отзывы: сетка и карусель — разные раскладки, автопрокрутка только у полосы', function () {
    $items = [
        ['quote' => 'Первый', 'name' => 'Иван', 'role' => 'Директор', 'rating' => 4],
        ['quote' => 'Второй', 'name' => 'Пётр'],
    ];

    $grid = variant_block('testimonials', ['variant' => 'grid', 'columns' => 3, 'items' => $items]);
    assert_contains('block-testimonials__grid', $grid['html']);
    assert_not_contains('data-carousel', $grid['html'], 'сетка полосой не едет');
    assert_contains('--grid-track', $grid['css'], 'последний ряд сетки выравнивается');

    $carousel = variant_block('testimonials', ['variant' => 'carousel', 'autoplay' => 7, 'items' => $items], 701);
    assert_contains('block-testimonials__track', $carousel['html']);
    assert_contains('data-carousel-autoplay="7"', $carousel['html']);

    // Оценка выводится значками, а подпись читает экранный диктор.
    assert_contains('testimonial__star is-on', $grid['html']);
    assert_contains('role="img"', $grid['html']);
    assert_contains('Директор', $grid['html']);

    // Ноль — оценки нет, ряда звёзд тоже.
    $noRating = variant_block('testimonials', ['items' => [['quote' => 'Без оценки', 'name' => 'Анна']]], 702);
    assert_not_contains('testimonial__rating', $noRating['html']);
});

test('Отзывы: нормализатор чистит вариант, колонки и оценку', function () {
    $data = TestimonialsBlockNormalizer::normalize([
        'variant' => 'мозаика',
        'columns' => '9',
        'autoplay' => '99',
        'items' => [['quote' => 'Текст', 'name' => 'Имя', 'rating' => '12']],
    ]);

    assert_same('carousel', $data['variant']);
    // Колонки выбираются из списка, а не набираются числом: значение, которого
    // в списке нет, приходит только из подделанной формы — берём умолчание, а
    // не ближайшее допустимое.
    assert_same(3, $data['columns']);
    assert_same(30, $data['autoplay'], 'секунды остаются числом с границами');
    assert_same(5, $data['items'][0]['rating']);
});

test('Партнёры: число логотипов в ряду, высота и обесцвечивание', function () {
    $items = [
        ['logo' => '/uploads/public/a.svg', 'name' => 'А', 'url' => 'https://example.org'],
        ['logo' => '/uploads/public/b.svg', 'name' => 'Б'],
    ];

    $block = variant_block('partners', [
        'columns' => 4,
        'logo_size' => 'large',
        'grayscale' => true,
        'autoplay' => 5,
        'all_text' => 'Все партнёры',
        'all_url' => '/partners',
        'items' => $items,
    ], 710);

    assert_contains('--partners-cols:4', $block['css']);
    assert_contains('block-partners--logo-large', $block['html']);
    assert_contains('block-partners--grayscale', $block['html']);
    assert_contains('data-carousel-autoplay="5"', $block['html']);
    assert_contains('href="/partners"', $block['html'], 'ссылка «Все партнёры» выводится общей шапкой');

    $plain = variant_block('partners', ['grayscale' => false, 'items' => $items], 711);
    assert_not_contains('block-partners--grayscale', $plain['html']);
});

test('Подписка: вариант «на фоне» без картинки становится полосой', function () {
    $withImage = SubscribeBlockNormalizer::normalize([
        'variant' => 'image',
        'image' => '/uploads/public/bg.jpg',
        'note' => 'Отписаться можно в один клик',
        'placeholder' => 'E-mail',
    ]);
    assert_same('image', $withImage['variant']);
    // Примечание проходит типографику (неразрывные пробелы), поэтому сверяем
    // содержание, а не побайтовое совпадение.
    assert_contains('Отписаться можно', $withImage['note']);
    assert_same('E-mail', $withImage['placeholder']);

    $withoutImage = SubscribeBlockNormalizer::normalize(['variant' => 'image', 'image' => '']);
    assert_same('band', $withoutImage['variant'], 'белый текст на белом фоне читать нельзя');
});

test('Текст с фото: пропорция, ширина кадра и кнопка', function () {
    $block = variant_block('text_image', [
        'title' => 'О проекте',
        'text' => 'Описание',
        'image' => '/uploads/public/photo.jpg',
        'image_ratio' => '4-3',
        'image_width' => 40,
        'button_text' => 'Подробнее',
        'button_url' => '/projects',
    ], 730);

    assert_contains('block-textimage--ratio-4-3', $block['html']);
    assert_contains('--textimage-visual:40fr', $block['css']);
    assert_contains('--textimage-info:60fr', $block['css']);
    assert_contains('href="/projects"', $block['html']);

    // Опасная ссылка кнопку не создаёт.
    $unsafe = variant_block('text_image', [
        'text' => 'Описание',
        'button_text' => 'Подробнее',
        'button_url' => 'javascript:alert(1)',
    ], 731);
    assert_not_contains('javascript:', $unsafe['html']);
    assert_not_contains('textimage__button', $unsafe['html']);
});

test('Иконка и текст: вариант вёрстки и выравнивание', function () {
    $items = [['icon_svg' => 'phone', 'rows' => "Приёмная | +998 71 200-00-00"]];

    $plain = variant_block('icon_text', ['variant' => 'plain', 'align' => 'center', 'items' => $items], 740);
    assert_contains('block-icon-text--plain', $plain['html']);
    assert_contains('block-icon-text--align-center', $plain['html']);

    $default = variant_block('icon_text', ['items' => $items], 741);
    assert_contains('block-icon-text--cards', $default['html']);
    assert_contains('block-icon-text--align-left', $default['html']);
});

test('Иконка и текст: позиция иконки не зависит от выравнивания', function () {
    $items = [['icon_svg' => 'phone', 'rows' => "Приёмная | +998 71 200-00-00"]];

    // Сочетание, недоступное раньше: иконка сверху, а текст по левому краю.
    $mixed = variant_block('icon_text', ['icon_position' => 'top', 'align' => 'left', 'items' => $items], 742);
    assert_contains('block-icon-text--icon-top', $mixed['html']);
    assert_contains('block-icon-text--align-left', $mixed['html']);

    $right = variant_block('icon_text', ['icon_position' => 'right', 'items' => $items], 743);
    assert_contains('block-icon-text--icon-right', $right['html']);

    // Значение вне списка откатывается к «слева».
    $bogus = variant_block('icon_text', ['icon_position' => 'снизу', 'items' => $items], 744);
    assert_contains('block-icon-text--icon-left', $bogus['html']);
});

test('Иконка и текст: подпись и значение можно поставить в одну строку', function () {
    $items = [['icon_svg' => 'phone', 'rows' => "Приёмная: | +998 71 200-00-00"]];

    $inline = variant_block('icon_text', ['rows_layout' => 'inline', 'items' => $items], 747);
    assert_contains('block-icon-text--rows-inline', $inline['html']);

    // По умолчанию подпись остаётся над значением.
    $stacked = variant_block('icon_text', ['items' => $items], 748);
    assert_contains('block-icon-text--rows-stacked', $stacked['html']);
});

test('Иконка и текст: вид, кегль и отступ подписи — настройки блока', function () {
    $items = [['icon_svg' => 'phone', 'rows' => "Hududlar rivoji | Tabiiy resurslar asosida"]];

    // Вид подписи приезжает классом: плашку рисует тема, а не инлайн-стиль.
    $badge = variant_block('icon_text', ['label_badge' => true, 'items' => $items], 750);
    assert_contains('block-icon-text--label-badge', $badge['html']);

    // Кегль и отступ — числом, в scoped CSS блока.
    $sized = variant_block('icon_text', ['label_size' => 15, 'label_gap' => 10, 'items' => $items], 751);
    assert_contains('#block-751 .icon-text__label{font-size:15px;}', $sized['css']);
    assert_contains('#block-751 .icon-text__row{gap:10px;}', $sized['css']);

    // Ноль означает «как в теме»: у собранных блоков ничего не появляется.
    $plain = variant_block('icon_text', ['items' => $items], 752);
    assert_not_contains('block-icon-text--label-badge', $plain['html']);
    assert_not_contains('.icon-text__label{font-size', $plain['css']);
    assert_not_contains('.icon-text__row{gap', $plain['css']);

    // Плашку рисует тема, иначе настройка ничего не меняла бы на выводе.
    $css = \theme_css();
    assert_contains('.block-icon-text--label-badge .icon-text__label', $css, 'у плашки есть правила');
});

test('Иконка и текст: каждая пара «подпись + значение» — своя строка', function () {
    // Две пары в одной карточке. Без обёртки они сливались в общий поток и в
    // строчном режиме переносились вперемешку: «Факс:» уезжал к первому номеру.
    $block = variant_block('icon_text', [
        'rows_layout' => 'inline',
        'items' => [['icon_svg' => 'phone', 'rows' => "Приёмная: | +998 71 200-00-00\nФакс: | +998 71 200-00-01"]],
    ], 749);

    assert_same(2, substr_count($block['html'], 'icon-text__row'), 'каждая пара обёрнута в свою строку');
    assert_true(
        (bool) preg_match(
            '#icon-text__row.*?Приёмная.*?200-00-00.*?</span>.*?icon-text__row.*?Факс.*?200-00-01#s',
            $block['html']
        ),
        'пары не должны перемешиваться между строками'
    );
});

test('Иконка и текст: у старых блоков позицию иконки задаёт прежнее выравнивание', function () {
    $items = [['icon_svg' => 'phone', 'rows' => "Приёмная | +998 71 200-00-00"]];

    // Блок сохранён до появления icon_position: «по центру» означало иконку сверху.
    $legacyCenter = variant_block('icon_text', ['align' => 'center', 'items' => $items], 745);
    assert_contains('block-icon-text--icon-top', $legacyCenter['html']);

    $legacyLeft = variant_block('icon_text', ['align' => 'left', 'items' => $items], 746);
    assert_contains('block-icon-text--icon-left', $legacyLeft['html']);
});

test('Проекты: варианты вёрстки — сетка, список и полоса с прокруткой (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec("DELETE FROM pages WHERE entity_type = 'project'");

    // Блок собирает проекты сам, поэтому заводим их в базе.
    $insert = $pdo->prepare(
        "INSERT INTO pages (title, slug, entity_type, `lead`, status, is_featured, sort_order, lang)
         VALUES (?, ?, 'project', ?, 'published', 1, ?, 'ru')"
    );
    foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $i => $slug) {
        $insert->execute([ucfirst($slug), 'variant-' . $slug, 'Анонс ' . $slug, $i]);
    }

    // Сетка — прежнее поведение: колонки редактора и ряды без дыр (пятёрка
    // карточек ложится 3+2, хвост растягивается).
    $grid = variant_block('projects_list', ['columns' => 3, 'limit' => 5], 720);
    assert_contains('block-projects block-projects--grid', $grid['html']);
    assert_contains('--grid-track', $grid['css'], 'у сетки считаются дорожки');
    assert_not_contains('data-carousel', $grid['html']);

    $list = variant_block('projects_list', ['variant' => 'list', 'limit' => 5], 721);
    assert_contains('block-projects--list', $list['html']);
    assert_same('', $list['css'], 'списку дорожки сетки не нужны');

    $carousel = variant_block('projects_list', ['variant' => 'carousel', 'autoplay' => 6, 'limit' => 5], 722);
    assert_contains('block-projects--carousel', $carousel['html']);
    assert_contains('data-carousel-autoplay="6"', $carousel['html']);
    assert_contains('data-carousel-track', $carousel['html']);
    assert_same(5, substr_count($carousel['html'], 'data-carousel-item'));

    // Один проект листать нечем: полосу не включаем, стрелки не рисуем.
    $pdo->exec("DELETE FROM pages WHERE entity_type = 'project' AND slug <> 'variant-alpha'");
    $single = variant_block('projects_list', ['variant' => 'carousel', 'limit' => 5], 723);
    assert_not_contains('data-carousel-track', $single['html']);
    assert_not_contains('carousel-nav', $single['html']);

    $pdo->exec("DELETE FROM pages WHERE entity_type = 'project'");
});

test('Обложка: картинка поверх фона встаёт над текстом, слева или справа', function () {
    $base = ['title' => 'Заголовок', 'art_image' => '/uploads/public/emblem.svg'];

    $above = variant_block('hero', $base, 730);
    assert_contains('block-hero__inner--art block-hero__inner--art-above', $above['html']);
    assert_contains('class="block-hero__art"', $above['html']);
    // Явная высота обязательна: SVG без пиксельных размеров схлопывается.
    assert_contains('height="120"', $above['html']);

    $left = variant_block('hero', $base + ['art_position' => 'left', 'art_size' => 'small'], 731);
    assert_contains('block-hero__inner--art-left', $left['html']);
    assert_contains('height="64"', $left['html']);
    // Слева — картинка идёт до текста.
    assert_true(
        strpos($left['html'], 'block-hero__art') < strpos($left['html'], 'block-hero__text'),
        'картинка слева выводится перед текстом'
    );

    $right = variant_block('hero', $base + ['art_position' => 'right', 'art_size' => 'large'], 732);
    assert_contains('block-hero__inner--art-right', $right['html']);
    assert_contains('height="200"', $right['html']);
    assert_true(
        strpos($right['html'], 'block-hero__art') > strpos($right['html'], 'block-hero__text'),
        'картинка справа выводится после текста'
    );

    // Чужой адрес в поле картинки в разметку не попадает.
    $unsafe = variant_block('hero', ['title' => 'Заголовок', 'art_image' => 'javascript:alert(1)'], 733);
    assert_not_contains('block-hero__art', $unsafe['html']);

    // Без картинки разметка обложки не меняется.
    $plain = variant_block('hero', ['title' => 'Заголовок'], 734);
    assert_not_contains('block-hero__inner--art', $plain['html']);

    // Без описания картинка декоративная и спрятана от скринридера.
    assert_contains('class="block-hero__art" aria-hidden="true"', $above['html']);
    assert_contains('alt=""', $above['html']);

    // С описанием это содержимое: alt заполнен, aria-hidden снят.
    $described = variant_block('hero', $base + ['art_alt' => 'Логотип программы'], 735);
    assert_contains('alt="Логотип программы"', $described['html']);
    assert_not_contains('class="block-hero__art" aria-hidden="true"', $described['html']);
});

test('Меньше движения: видео и автопрокрутка спрашивают общий признак, а не только медиазапрос', function () {
    $themeInit = (string) file_get_contents(APP_ROOT . '/public/assets/js/theme-init.js');
    $frontend = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    $slider = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/slider.js');
    $a11y = (string) file_get_contents(APP_ROOT . '/public/assets/js/a11y.js');

    // Общий признак учитывает и системную просьбу, и тумблер панели: CSS гасит
    // только анимации, а видео и таймеры о нём не знают.
    assert_contains('window.asdrReduceMotion', $themeInit);
    assert_contains("data-a11y-motion", $themeInit);

    // Потребители спрашивают признак, а не медиазапрос напрямую.
    assert_contains('window.asdrReduceMotion', $frontend);
    assert_contains('window.asdrReduceMotion', $slider);
    assert_not_contains("var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;", $slider);

    // Панель сообщает о смене настройки, иначе фон продолжит крутиться.
    assert_contains('asdr:motion-change', $a11y);
    assert_contains('asdr:motion-change', $frontend);
});

test('Обложка: вертикаль текста и оформление слайда наследуется от блока', function () {
    // Вертикаль: класс на корне, умолчание — по центру.
    $bottom = variant_block('hero', ['title' => 'Заголовок', 'text_align_y' => 'bottom'], 740);
    assert_contains('block-hero--y-bottom', $bottom['html']);
    $default = variant_block('hero', ['title' => 'Заголовок'], 741);
    assert_contains('block-hero--y-center', $default['html']);
    $broken = variant_block('hero', ['title' => 'Заголовок', 'text_align_y' => 'вниз'], 742);
    assert_contains('block-hero--y-center', $broken['html'], 'мусор заменяется умолчанием');

    // Слайд без своих настроек берёт оформление обложки.
    $inherited = variant_block('hero', [
        'overlay_enabled' => true,
        'overlay_mode' => 'solid',
        'panel_enabled' => true,
        'art_image' => '/uploads/public/emblem.svg',
        'slides' => [
            ['title' => 'Первый', 'image' => '/uploads/public/one.jpg'],
            ['title' => 'Второй', 'image' => '/uploads/public/two.jpg'],
        ],
    ], 743);
    assert_same(2, substr_count($inherited['html'], 'block-hero__scrim--solid'), 'затемнение обложки на обоих слайдах');
    assert_same(2, substr_count($inherited['html'], 'block-hero__text--panel'), 'подложка обложки на обоих слайдах');
    assert_same(2, substr_count($inherited['html'], 'block-hero__art-img'), 'картинка обложки повторяется на слайдах');

    // Слайд может переопределить каждую настройку по отдельности.
    $own = variant_block('hero', [
        'overlay_enabled' => true,
        'overlay_mode' => 'solid',
        'panel_enabled' => true,
        'slides' => [
            ['title' => 'Первый', 'image' => '/uploads/public/one.jpg', 'overlay' => 'off', 'panel' => 'off'],
            ['title' => 'Второй', 'image' => '/uploads/public/two.jpg', 'overlay_mode' => 'gradient'],
        ],
    ], 744);
    assert_same(1, substr_count($own['html'], 'block-hero__scrim'), 'слайд с выключенным затемнением остаётся без него');
    assert_same(0, substr_count($own['html'], 'block-hero__scrim--solid'), 'второй слайд перешёл на градиент');
    assert_same(1, substr_count($own['html'], 'block-hero__text--panel'), 'подложка снята только у первого слайда');
});

test('Обложка: у телефона своя высота, свой кадр и постер вместо видео', function () {
    // Кадр для телефона — источник с медиазапросом впереди десктопного.
    $mobile = variant_block('hero', [
        'title' => 'Заголовок',
        'bg_type' => 'image',
        'image' => '/uploads/public/wide.jpg',
        'image_mobile' => '/uploads/public/tall.jpg',
    ], 745);
    assert_contains('<source media="(max-width: 720px)"', $mobile['html']);
    assert_contains('/uploads/public/tall.jpg', $mobile['html']);
    assert_true(
        strpos($mobile['html'], 'tall.jpg') < strpos($mobile['html'], '<img'),
        'мобильный источник стоит до <img>, иначе браузер его не выберет'
    );

    // Без мобильного кадра лишних источников не появляется.
    $plain = variant_block('hero', [
        'title' => 'Заголовок',
        'bg_type' => 'image',
        'image' => '/uploads/public/wide.jpg',
    ], 746);
    assert_not_contains('media="(max-width: 720px)"', $plain['html']);

    // Чужой адрес в мобильном поле игнорируется, как и в остальных медиаполях.
    $unsafe = variant_block('hero', [
        'title' => 'Заголовок',
        'bg_type' => 'image',
        'image' => '/uploads/public/wide.jpg',
        'image_mobile' => 'javascript:alert(1)',
    ], 747);
    assert_not_contains('media="(max-width: 720px)"', $unsafe['html']);

    // Высота телефона — отдельным медиазапросом в scoped CSS блока.
    $height = variant_block('hero', [
        'title' => 'Заголовок',
        'height' => 'full',
        'height_mobile' => 'custom',
        'custom_height_mobile' => '420px',
    ], 748);
    assert_contains('block-hero--h-full', $height['html'], 'десктопная высота не меняется');
    assert_contains('@media (max-width:720px)', $height['css']);
    assert_contains('min-height:420px', $height['css']);
    $badHeight = variant_block('hero', [
        'title' => 'Заголовок',
        'height_mobile' => 'custom',
        'custom_height_mobile' => '420 пикселей',
    ], 749);
    assert_not_contains('@media (max-width:720px)', $badHeight['css'], 'мусор в высоте не попадает в CSS');

    // Видео на телефоне: по умолчанию только постер, файл не скачивается.
    $video = variant_block('hero', [
        'title' => 'Заголовок',
        'bg_type' => 'video',
        'video_url' => '/uploads/public/bg.mp4',
        'image' => '/uploads/public/poster.jpg',
    ], 750);
    assert_contains('data-hero-video-mobile="poster"', $video['html']);
    assert_contains('poster="/uploads/public/poster.jpg"', $video['html']);
    $playing = variant_block('hero', [
        'title' => 'Заголовок',
        'bg_type' => 'video',
        'video_url' => '/uploads/public/bg.mp4',
        'video_mobile' => 'play',
    ], 751);
    assert_not_contains('data-hero-video-mobile', $playing['html']);

    // Решение принимает фронтенд по ширине окна — иначе кэш страницы общий.
    $frontend = (string) file_get_contents(__DIR__ . '/../../public/assets/js/frontend.js');
    assert_contains('data-hero-video-mobile', $frontend);
    assert_contains('(max-width: 720px)', $frontend);
});

test('Карточки с фото: вариант «текст под фото» без затемнения поверх снимка', function () {
    $items = [[
        'title' => 'Проект',
        'text' => 'Описание проекта',
        'image' => '/uploads/public/one.jpg',
        'url' => '/projects/one',
    ]];

    $below = variant_block('cards_grid', ['variant' => 'image_below', 'items' => $items], 760);
    assert_contains('block-imgcards--below', $below['html']);
    assert_contains('imgcard imgcard--below', $below['html']);
    assert_not_contains('imgcard__overlay', $below['html'], 'текст лежит на подложке, затемнение не нужно');
    assert_contains('imgcard__text', $below['html']);

    // Прежний вариант не изменился: подпись остаётся на снимке под градиентом.
    $over = variant_block('cards_grid', ['variant' => 'image', 'items' => $items], 761);
    assert_contains('imgcard__overlay', $over['html']);
    assert_not_contains('imgcard--below', $over['html']);

    // Мусор в варианте — это карточки с иконками, а не пустая секция.
    $broken = variant_block('cards_grid', ['variant' => 'фото снизу', 'items' => $items], 762);
    assert_contains('block-cards', $broken['html']);

    // Источник «Проекты» больше не навязывает подпись поверх фото: вариант
    // с текстом под снимком контроллер обязан сохранить.
    $controller = (string) file_get_contents(__DIR__ . '/../../app/Controllers/Admin/BlockController.php');
    assert_contains("in_array(\$collected['variant'] ?? '', ['image', 'image_below'], true)", $controller);
});

test('Карточки: число колонок действует у всех вариантов — и в сетке, и в слайдере', function () {
    $items = array_fill(0, 6, ['title' => 'Карточка', 'image' => '/uploads/public/one.jpg', 'icon_svg' => 'star']);

    // Прежде вариант с фотографией рисовался жёсткой четвёркой: настройка
    // «Колонок» до него не доходила ни в сетке, ни в кадре слайдера.
    $photo = variant_block('cards_grid', ['variant' => 'image', 'columns' => 3, 'items' => $items], 770);
    assert_contains('--cards-cols:3', $photo['css'], 'колонки объявлены блоком, а не вариантом');

    $icons = variant_block('cards_grid', ['variant' => 'icon', 'columns' => 2, 'items' => $items], 771);
    assert_contains('--cards-cols:2', $icons['css']);

    $theme = theme_css();
    assert_contains('.imgcards-grid { display: grid; grid-template-columns: repeat(var(--cards-cols, 4)', $theme);
    assert_true(
        !str_contains($theme, '.imgcards-grid--carousel .imgcard { flex: 0 0 calc((100% - 60px) / 4)'),
        'ширина карточки в кадре слайдера больше не записана числом'
    );
    assert_contains('.cards-track > * { flex: 0 0 calc((100% - var(--card-gap, 20px) * (var(--cards-cols, 4) - 1))', $theme);
});

test('Карточки: раскладка выбирается редактором — сетка, слайдер или авто', function () {
    $items = array_fill(0, 6, ['title' => 'Карточка', 'image' => '/uploads/public/one.jpg', 'icon_svg' => 'star']);

    // «Авто» у карточек с фотографией — прежнее поведение: карточек больше,
    // чем колонок, значит полоса.
    $auto = variant_block('cards_grid', ['variant' => 'image', 'layout' => 'auto', 'columns' => 4, 'items' => $items], 772);
    assert_contains('imgcards-grid--carousel', $auto['html']);

    // Тех же шести карточек хватает на шесть колонок — полосе взяться неоткуда.
    $autoWide = variant_block('cards_grid', ['variant' => 'image', 'layout' => 'auto', 'columns' => 5, 'items' => array_slice($items, 0, 5)], 773);
    assert_not_contains('imgcards-grid--carousel', $autoWide['html']);

    // «Сетка» снимает прокрутку целиком, вместе со стрелками и мобильной полосой.
    $grid = variant_block('cards_grid', ['variant' => 'image', 'layout' => 'grid', 'columns' => 4, 'items' => $items], 774);
    assert_not_contains('data-carousel', $grid['html']);
    assert_not_contains('imgcards-grid--mobile-carousel', $grid['html']);

    // «Слайдер» доступен и вариантам без фотографии — там его не было вовсе.
    $iconSlider = variant_block('cards_grid', ['variant' => 'icon', 'layout' => 'slider', 'items' => $items], 775);
    assert_contains('cards-grid cards-track', $iconSlider['html']);
    assert_contains('data-carousel-track', $iconSlider['html']);
    assert_contains('data-carousel-item', $iconSlider['html']);

    $compactSlider = variant_block('cards_grid', ['variant' => 'compact', 'layout' => 'slider', 'items' => $items], 776);
    assert_contains('cat-grid cards-track', $compactSlider['html']);
    assert_contains('block-categories__head', $compactSlider['html']);

    // Собранные раньше страницы не меняются: у вариантов без фотографии
    // «авто» — это сетка, как и было.
    $iconAuto = variant_block('cards_grid', ['variant' => 'icon', 'items' => $items], 777);
    assert_not_contains('data-carousel', $iconAuto['html']);
    $compactAuto = variant_block('cards_grid', ['variant' => 'compact', 'items' => $items], 778);
    assert_not_contains('data-carousel', $compactAuto['html']);
});

test('Карточки: разметка стрелок и точек — одна на все варианты', function () {
    $partial = (string) file_get_contents(APP_ROOT . '/templates/blocks/partials/carousel_nav.php');
    $template = (string) file_get_contents(APP_ROOT . '/templates/blocks/cards_grid.php');

    assert_contains('data-carousel-nav hidden', $partial);
    assert_same(3, substr_count($template, "include __DIR__ . '/partials/carousel_nav.php'"), 'все три ветки берут одну разметку');
    assert_true(!str_contains($template, 'data-carousel-prev'), 'своей копии стрелок у шаблона не осталось');
});

test('Карточки в колонке конструктора считают раскладку по ширине колонки', function () {
    $polish = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');

    // Колонка объявлена контейнером измерения; без имени правило @container
    // цеплялось бы к любому контейнеру-предку.
    assert_contains('.cms-columns__col {', $polish);
    assert_contains('container-type: inline-size;', $polish);
    assert_contains('container-name: cms-col;', $polish);

    // Сетка и кадр слайдера сжимаются вместе: узкая колонка 1:3 остаётся узкой
    // на любом экране, и медиазапрос по ширине окна о ней ничего не знает.
    assert_contains('@container cms-col (max-width: 720px)', $polish);
    assert_contains('@container cms-col (max-width: 480px)', $polish);
    foreach (['.cards-grid', '.imgcards-grid', '.cards-track > *'] as $selector) {
        assert_contains($selector, $polish, $selector . ' участвует в контейнерных правилах');
    }

    // Файл подключается после общей темы — иначе её медиазапросы перекрыли бы
    // контейнерные правила при равной специфичности.
    $assets = (string) file_get_contents(APP_ROOT . '/app/Core/FrontendAssets.php');
    $themeAt = strpos($assets, '/assets/css/gov-theme.css');
    $polishAt = strpos($assets, '/assets/css/public-layout-polish.css');
    assert_true($themeAt !== false && $polishAt !== false && $polishAt > $themeAt, 'полиш подключается после темы');
});
