<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

/*
 * Мелочи из свежих релизов чужих конструкторов, легшие на наши блоки:
 * автопереключение вкладок с отсчётом и пояснением, бегущая строка логотипов,
 * машиночитаемая оценка в отзывах.
 *
 * Общее требование ко всем трём: без JavaScript и при просьбе «меньше
 * движения» страница остаётся рабочей, а не пустой и не дёргающейся.
 */

/**
 * @param array<string, mixed> $data
 */
function rhythm_block_html(string $type, array $data, int $id = 0): string
{
    $rendered = BlockRenderer::render([
        'id' => $id,
        'type' => $type,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) $rendered['html'];
}

test('Вкладки: автопереключение приходит атрибутом и полосой отсчёта', function () {
    $items = [['title' => 'Первая'], ['title' => 'Вторая']];

    $off = rhythm_block_html('tabs', ['items' => $items]);
    assert_not_contains('data-tabs-autoplay', $off, 'по умолчанию вкладки не переключаются сами');
    assert_not_contains('cms-tabs__tab-progress', $off, 'без автопереключения отсчитывать нечего');

    $on = rhythm_block_html('tabs', ['items' => $items, 'autoplay' => 8]);
    assert_contains('data-tabs-autoplay="8"', $on);
    assert_contains('cms-tabs__tab-progress', $on);

    // Одна вкладка — переключать не на что.
    $single = rhythm_block_html('tabs', ['items' => [['title' => 'Одна']], 'autoplay' => 8]);
    assert_not_contains('data-tabs-autoplay', $single);
});

test('Вкладки: пояснение стоит над содержимым, а не в полосе вкладок', function () {
    $html = rhythm_block_html('tabs', ['items' => [
        ['title' => 'Заявка', 'text' => 'Как подать документы'],
        ['title' => 'Сроки'],
    ]]);

    assert_contains('<p class="cms-tabs__panel-note">Как подать документы</p>', $html);
    // В самой вкладке остаётся только название: вторая строка ломает ряд.
    assert_not_contains('cms-tabs__tab-text">Как подать', $html);
});

test('Вкладки: скрипт уступает выбору посетителя и настройке «меньше движения»', function () {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/tabs.js');

    assert_contains('asdrReduceMotion', $js, 'общий признак «меньше движения», а не свой медиазапрос');
    assert_contains('stoppedByUser', $js, 'после ручного выбора автопоказ прекращается');
    assert_contains("addEventListener('asdr:motion-change'", $js, 'настройку переключают на лету');
    assert_contains("--cms-tabs-progress", $js, 'долю отсчёта считает скрипт');
});

test('Партнёры: бегущая строка выводит набор дважды, копию прячет от диктора', function () {
    $items = [
        ['logo' => '/uploads/public/a.png', 'name' => 'Альфа', 'url' => 'https://example.org'],
        ['logo' => '/uploads/public/b.png', 'name' => 'Бета', 'url' => ''],
        ['logo' => '/uploads/public/c.png', 'name' => 'Гамма', 'url' => ''],
    ];

    $marquee = rhythm_block_html('partners', ['variant' => 'marquee', 'items' => $items]);
    assert_contains('block-partners--marquee', $marquee);
    assert_contains('block-partners__marquee-track', $marquee);
    assert_same(6, substr_count($marquee, 'block-partners__item'), 'набор выведен дважды — иначе в шве видна пустота');
    assert_same(1, substr_count($marquee, 'href="https://example.org"'), 'копия не повторяет ссылку');
    assert_contains('aria-hidden="true" title="Альфа"', $marquee, 'копия скрыта от диктора');
    assert_not_contains('data-carousel', $marquee, 'лента едет сама, поведение карусели ей не нужно');

    // Двух логотипов для ленты мало: она читается как мигание.
    $short = rhythm_block_html('partners', ['variant' => 'marquee', 'items' => array_slice($items, 0, 2)]);
    assert_not_contains('block-partners--marquee', $short);

    $row = rhythm_block_html('partners', ['items' => $items]);
    assert_not_contains('block-partners__marquee', $row, 'обычный вариант остаётся сеткой');
});

test('Партнёры: лента стоит под курсором и при фокусе внутри', function () {
    $css = theme_css() . (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');

    assert_contains('.block-partners__marquee:hover .block-partners__marquee-track,', $css);
    assert_contains('.block-partners__marquee:focus-within .block-partners__marquee-track { animation-play-state: paused; }', $css);
    // Половина ширины дорожки совпадает с набором только вместе с отступом
    // после последнего логотипа — иначе в шве не хватает промежутка.
    assert_contains('padding-inline-end: var(--card-gap, 24px);', $css);
    assert_contains('to { transform: translateX(-50%); }', $css);
});

test('Отзывы: оценка и автор машиночитаемы', function () {
    $html = rhythm_block_html('testimonials', ['items' => [
        ['quote' => 'Работают быстро', 'name' => 'И. Иванов', 'role' => 'директор', 'company' => 'ООО «Мост»', 'rating' => 4],
    ]]);

    assert_contains('itemtype="https://schema.org/Review"', $html);
    assert_contains('itemprop="reviewRating"', $html);
    assert_contains('<meta itemprop="ratingValue" content="4">', $html);
    assert_contains('itemprop="reviewBody"', $html);
    assert_contains('itemtype="https://schema.org/Person"', $html);
    // itemReviewed нет намеренно: блок ставят и под отзывы о проекте, а не
    // только об организации, и объявлять предмет отзыва было бы неправдой.
    assert_not_contains('itemprop="itemReviewed"', $html);
});
