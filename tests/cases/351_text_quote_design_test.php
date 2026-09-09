<?php

declare(strict_types=1);

use App\Controllers\Admin\BlockController;
use App\Core\BlockRenderer;
use App\Core\BlockTypeRegistry;

/*
 * Оформление акцентной цитаты («Текст + акцентная цитата»).
 *
 * Прежде карточка цитаты была наглухо зашита в CSS: тёмно-синий фон, белый
 * текст и знак «“» в левом верхнем углу размером с заголовок. Ни одного из
 * этих решений редактор поменять не мог, а сам знак был `content` у
 * псевдоэлемента — то есть ни другого символа, ни значка из набора туда
 * подставить было нечем.
 *
 * И жило всё это в `public-editorial-pages.css`, под селектором
 * `.editorial-page__content`: на главной и на странице, начинающейся с
 * обложки, вариант предлагался в редакторе и не давал ничего.
 */

/** @param array<string, mixed> $data */
function render_text_block(array $data): array
{
    return BlockRenderer::render([
        'id' => 51,
        'type' => 'text',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);
}

test('Оформление цитаты не зависит от разметки страницы', function (): void {
    // Правила карточки принадлежат блоку, поэтому лежат в теме. Останутся в
    // редакционном файле — вариант снова перестанет работать вне
    // `.editorial-page__content`, и заметить это можно будет только глазами.
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    $editorial = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');

    assert_contains('.block-text__quote {', $theme);
    assert_contains('.block-text--spotlight .block-text__layout {', $theme);
    assert_not_contains('.editorial-page__content .block-text__quote', $editorial);
});

test('Знак кавычки — элемент разметки, а не content псевдоэлемента', function (): void {
    // Псевдоэлемент умеет показать только строку: значок из спрайта в него не
    // вставить. Диктору знак не адресован — иначе он читает кавычку перед
    // каждой цитатой.
    $html = (string) render_text_block(['variant' => 'spotlight', 'quote' => 'Цитата'])['html'];

    assert_contains('<span class="block-text__quote-mark" aria-hidden="true">', $html);
    assert_contains('block-text__quote--mark-top-left', $html);

    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    assert_not_contains('.block-text__quote::before', $theme, 'знак рисует разметка, а не псевдоэлемент');
});

test('Блок, собранный до появления настроек, выглядит по-прежнему', function (): void {
    // Умолчания повторяют прежний вид: символ «“» слева сверху, цвета и размер
    // из темы. Настройка «как в теме» — это отсутствующая переменная, поэтому
    // scoped CSS у такого блока не появляется вовсе.
    $rendered = render_text_block(['variant' => 'spotlight', 'quote' => 'Цитата']);

    assert_contains('">“</span>', (string) $rendered['html']);
    assert_same('', (string) $rendered['css'], 'у блока без своих настроек нет и своего CSS');
});

test('Каждая настройка цитаты доезжает до вывода', function (): void {
    $rendered = render_text_block([
        'variant' => 'spotlight',
        'quote' => 'Цитата',
        'quote_bg' => '#f5f7fa',
        'quote_mark' => 'icon',
        'quote_mark_icon' => 'quote',
        'quote_mark_size' => 120,
        'quote_mark_color' => '#e63946',
        'quote_mark_position' => 'bottom-right',
    ]);
    $css = (string) $rendered['css'];
    $html = (string) $rendered['html'];

    assert_contains('#block-51 .block-text__quote{', $css);
    assert_contains('--quote-bg:#f5f7fa;', $css);
    assert_contains('--quote-mark-color:#e63946;', $css);
    assert_contains('--quote-mark-size:120px;', $css);
    assert_contains('block-text__quote--mark-bottom-right', $html);
    assert_contains('#tabler-quote', $html, 'знаком бывает значок из набора, а не только символ');

    // Свой цвет текста главнее подбора по контрасту.
    $own = (string) render_text_block([
        'variant' => 'spotlight',
        'quote' => 'Цитата',
        'quote_bg' => '#f5f7fa',
        'quote_color' => '#123456',
    ])['css'];
    assert_contains('--quote-fg:#123456;', $own);
});

test('Цвет текста подбирается по фону, если редактор его не задал', function (): void {
    // Белый текст на светлой заливке — это 1.1:1, то есть цитаты не видно
    // вовсе. Поэтому пустой цвет текста означает не «белый», а «посчитать».
    $light = (string) render_text_block(['variant' => 'spotlight', 'quote' => 'Ц', 'quote_bg' => '#ffffff'])['css'];
    $dark = (string) render_text_block(['variant' => 'spotlight', 'quote' => 'Ц', 'quote_bg' => '#0f2b46'])['css'];

    assert_contains('--quote-fg:#0b1a30;', $light);
    assert_contains('--quote-fg:#ffffff;', $dark);
});

test('Настройки цитаты объявлены в умолчаниях, в форме и при сохранении', function (): void {
    // Тип «Текст» живёт по-старому (без BlockFieldSchema), поэтому у настройки
    // четыре места, и разойтись они могут молча: поле формы есть, а ветка
    // сохранения о нём не знает — значение теряется при первом сохранении.
    $defaults = BlockTypeRegistry::defaultsFor('text');
    $editor = block_editor_markup();
    $saving = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');

    // Готовые виджеты админки печатают `name` уже на рендере, поэтому в
    // разметке формы они видны вызовом, а не атрибутом.
    $widgets = ['quote_bg' => 'colorField', 'quote_color' => 'colorField',
        'quote_mark_color' => 'colorField', 'quote_mark_icon' => 'iconField'];

    foreach ([
        'quote_bg', 'quote_color', 'quote_mark', 'quote_mark_text',
        'quote_mark_icon', 'quote_mark_size', 'quote_mark_color', 'quote_mark_position',
    ] as $key) {
        assert_true(array_key_exists($key, $defaults), "нет умолчания: {$key}");
        assert_contains(
            isset($widgets[$key]) ? $widgets[$key] . "('" . $key . "'" : 'name="' . $key . '"',
            $editor,
            "поле {$key} недоступно редактору"
        );
        assert_contains("'{$key}' => ", $saving, "значение {$key} не сохраняется");
    }
});

test('Подделанные значения заменяются умолчанием', function (): void {
    $oldPost = $_POST;
    $_POST = [
        'variant' => 'spotlight',
        'quote' => 'Цитата',
        'quote_mark' => 'marquee',
        'quote_mark_position' => 'diagonal',
        'quote_mark_size' => '9000',
        'quote_mark_text' => '   «текст целиком   ',
        'quote_mark_icon' => '<script>',
    ];

    try {
        $method = new ReflectionMethod(BlockController::class, 'collectData');
        $data = $method->invoke(new BlockController(), 'text', 'ru');

        assert_same('text', $data['quote_mark']);
        assert_same('top-left', $data['quote_mark_position']);
        assert_same(240, $data['quote_mark_size'], 'размер ограничен сверху');
        assert_same('«т', $data['quote_mark_text'], 'в углу карточки знак, а не строка текста');
        assert_same('', $data['quote_mark_icon']);
    } finally {
        $_POST = $oldPost;
    }
});
