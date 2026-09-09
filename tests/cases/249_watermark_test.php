<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer;
use App\Core\BlockRenderer;
use App\Core\Hero\HeroSlideData;
use App\Models\HeroSlide;
use App\Models\HeroSlideTranslation;

// Фоновая надпись — крупное слово за содержимым (у слайда обложки и у секции).
// Это декорация из текста, поэтому у неё три обязательства: перевод, молчание
// у диктора и прозрачность для мыши. Размер задаётся числом: нужный кегль
// зависит от длины слова, пресетом его не угадать.
//
// Настроек ровно пять и они одинаковы на обеих поверхностях: текст, размер,
// привязка по горизонтали и вертикали, заметность. Точные смещения, контур с
// толщиной, свой цвет и выбор семейства убраны — слово рисуется цветом текста
// секции или обложки, поэтому остаётся видимым на любой схеме.

test('Фоновая надпись слайда: размер числом, с границами', function () {
    $d = HeroSlideData::normalize([
        'watermark' => '  aerion  ',
        'watermark_size' => '30',
        'watermark_x' => 'right',
        'watermark_y' => 'top',
        'watermark_opacity' => '18',
    ]);
    assert_same('aerion', $d['watermark']);
    assert_same(30, $d['watermark_size']);
    assert_same('right', $d['watermark_x']);
    assert_same('top', $d['watermark_y']);
    assert_same(18, $d['watermark_opacity']);

    // Убранные ручки не возвращаются молча: у слайда их нет ни в умолчаниях,
    // ни после нормализации присланной формы.
    foreach (['watermark_dx', 'watermark_dy', 'watermark_style',
              'watermark_stroke', 'watermark_color', 'watermark_font'] as $gone) {
        assert_false(array_key_exists($gone, HeroSlideData::defaults()), 'ручка надписи вернулась: ' . $gone);
        assert_false(array_key_exists($gone, $d), 'ручка надписи сохранилась из формы: ' . $gone);
    }

    // Пусто — умолчание, а не край диапазона и не ноль: иначе очистка поля
    // схлопывала бы надпись в точку.
    $empty = HeroSlideData::normalize(['watermark' => 'x', 'watermark_size' => '', 'watermark_opacity' => '']);
    assert_same(22, $empty['watermark_size']);
    assert_same(12, $empty['watermark_opacity']);

    // За границы не выпускаем: 300vw — это надпись шириной в три экрана.
    $huge = HeroSlideData::normalize(['watermark' => 'x', 'watermark_size' => '300']);
    assert_same(60, $huge['watermark_size']);

    // Чужие значения привязки откатываются к умолчанию, а не попадают в класс.
    $bad = HeroSlideData::normalize(['watermark' => 'x', 'watermark_x' => 'куда-нибудь']);
    assert_same('center', $bad['watermark_x']);
});

test('Свой CSS-класс слайда: только имена классов, всё остальное отбрасывается', function () {
    // Класс уходит в атрибут class — набор символов ограничен жёстко.
    $d = HeroSlideData::normalize(['css_class' => 'hero-fantasy 123bad --nope hero_2']);
    assert_same('hero-fantasy hero_2', $d['css_class'], 'класс обязан начинаться с буквы');

    // Кавычка закрыла бы атрибут — такое имя не класс.
    $inject = HeroSlideData::normalize(['css_class' => 'ok" onload="alert(1)']);
    assert_not_contains('"', $inject['css_class']);
    assert_not_contains('onload', $inject['css_class'], 'обработчик события классом не притворяется');

    // Дубли и лишнее количество: пять — потолок.
    $many = HeroSlideData::normalize(['css_class' => 'a b c d e f g a']);
    assert_true(count(explode(' ', $many['css_class'])) <= 5);

    $tags = HeroSlideData::normalize(['css_class' => '<script>']);
    assert_same('', $tags['css_class']);
});

test('Фоновая надпись секции появляется только вместе с текстом', function () {
    // Без текста ключей оформления в данных блока нет: пустая надпись не
    // должна ни включать обрезку секции, ни плодить переменные в CSS.
    $off = BlockPresentationNormalizer::normalize(['watermark' => '   ', 'watermark_size' => '40']);
    assert_true(!array_key_exists('_watermark', $off));
    assert_true(!array_key_exists('_watermark_size', $off));

    $on = BlockPresentationNormalizer::normalize([
        'watermark' => 'TOP 5',
        'watermark_size' => '20',
        'watermark_x' => 'right',
        'watermark_y' => 'top',
        'watermark_opacity' => '9',
    ]);
    assert_same('TOP 5', $on['_watermark']);
    assert_same(20, $on['_watermark_size']);
    assert_same('right', $on['_watermark_x']);
    assert_same('top', $on['_watermark_y']);
    assert_same(9, $on['_watermark_opacity']);
});

test('Разметка надписи: молчит у диктора и не перехватывает клики', function () {
    $rendered = BlockRenderer::render([
        'id' => 42,
        'type' => 'text',
        'data' => json_encode([
            'content' => 'Текст секции',
            '_watermark' => 'TOP 5',
            '_watermark_size' => 20,
            '_watermark_x' => 'right',
            '_watermark_y' => 'top',
            '_watermark_opacity' => 9,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $html = (string) $rendered['html'];

    assert_contains('cms-block--has-watermark', $html);
    assert_contains('cms-block__watermark--x-right', $html);
    assert_contains('cms-block__watermark--y-top', $html);
    // Диктор не должен читать декорацию посреди содержимого секции.
    assert_contains('aria-hidden="true">TOP 5</span>', $html);

    // Прозрачность и размер — переменными в scoped CSS: инлайн-стили в блоках
    // запрещены, их стережёт отдельный тест.
    $css = (string) $rendered['css'];
    assert_contains('--block-watermark-opacity:0.09', $css);
    assert_contains('--block-watermark-size:20vw', $css);
    assert_not_contains('style="', $html);

    // Слово шире секции обязано обрезаться её краем, а не растягивать
    // страницу; содержимое лежит над надписью, иначе теряет контраст.
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    assert_contains('.cms-block--has-watermark', $theme);
    assert_contains('overflow-x: clip', $theme);
    assert_contains('pointer-events: none', $theme);
    assert_contains('.cms-block--has-watermark > *:not(.cms-block__watermark)', $theme);

    $hero = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    assert_contains('.hero__watermark--x-center', $hero);
    assert_contains('pointer-events: none', $hero);
});

test('Фоновая надпись слайда переводится наравне с заголовком', function () {
    // Слайд один на все языки, переводится только текст (механизм А) —
    // значит надпись обязана быть и в списке полей перевода, и в наложении.
    assert_true(in_array('watermark', HeroSlideTranslation::FIELDS, true));

    $row = HeroSlide::applyTranslation(
        ['id' => 1, 'data' => ['watermark' => 'aerion', 'title' => 'Заголовок']],
        ['watermark' => 'agentlik']
    );
    assert_same('agentlik', $row['data']['watermark']);

    // Пустой перевод не затирает основной язык.
    $keep = HeroSlide::applyTranslation(
        ['id' => 1, 'data' => ['watermark' => 'aerion']],
        ['watermark' => '  ']
    );
    assert_same('aerion', $keep['data']['watermark']);
});

test('Размер надписи не берётся из шрифтовой шкалы', function () {
    // Шкала --step-* существует для читаемого текста; декорация во всю ширину
    // должна расти вместе с экраном, поэтому она в vw. Тест закрепляет выбор,
    // чтобы правка «привести к шкале» не сломала приём.
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    assert_contains('font-size: var(--block-watermark-size, 22vw)', $theme);

    $hero = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    assert_contains('font-size: var(--hero-watermark-size, 22vw)', $hero);
});

test('Настройки надписи одинаковы у секции и у обложки, лишних не осталось', function () {
    $normalized = BlockPresentationNormalizer::normalize([
        'watermark' => 'SERVICES',
        'watermark_x' => 'center',
        'watermark_y' => 'middle',
        'watermark_size' => '25',
        'watermark_opacity' => '18',
        // Убранные ручки приходят из старой формы — они не должны сохраняться.
        'watermark_style' => 'outline',
        'watermark_stroke' => '4',
        'watermark_dx' => '10',
        'watermark_dy' => '-20',
        'watermark_font' => 'body',
        'watermark_color' => '#009bbe',
    ]);

    assert_same('SERVICES', $normalized['_watermark']);
    assert_same('center', $normalized['_watermark_x']);
    assert_same('middle', $normalized['_watermark_y']);
    assert_same(25, $normalized['_watermark_size']);
    assert_same(18, $normalized['_watermark_opacity']);
    foreach (['_watermark_style', '_watermark_stroke', '_watermark_dx',
              '_watermark_dy', '_watermark_font', '_watermark_color'] as $gone) {
        assert_false(array_key_exists($gone, $normalized), 'ручка надписи вернулась к секции: ' . $gone);
    }

    // Обе поверхности описываются одним набором ключей — иначе одна из форм
    // молча обрастает настройками, которых нет у другой.
    $heroKeys = array_values(array_filter(
        array_keys(HeroSlideData::defaults()),
        static fn (string $k): bool => str_starts_with($k, 'watermark')
    ));
    sort($heroKeys);
    assert_same(
        ['watermark', 'watermark_opacity', 'watermark_size', 'watermark_x', 'watermark_y'],
        $heroKeys,
        'набор настроек надписи у обложки разошёлся с секцией'
    );

    $rendered = BlockRenderer::render([
        'id' => 43,
        'type' => 'cards_grid',
        'data' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
    ]);
    $html = (string) $rendered['html'];
    $css = (string) $rendered['css'];

    assert_contains('cms-block--has-watermark', $html);
    assert_contains('cms-block__watermark--x-center', $html);
    assert_contains('cms-block__watermark--y-middle', $html);
    assert_contains('aria-hidden="true">SERVICES</span>', $html);
    assert_true(strpos($html, 'watermark--outline') === false, 'класс контура остался в разметке');

    assert_contains('--block-watermark-opacity:0.18', $css);
    assert_contains('--block-watermark-size:25vw', $css);
    foreach (['--block-watermark-dx', '--block-watermark-dy',
              '--block-watermark-stroke', '--block-watermark-ink'] as $gone) {
        assert_true(strpos($css, $gone) === false, 'мёртвая переменная надписи: ' . $gone);
    }

    // Поля есть в обеих формах: у секции — в блоке настроек, у слайда обложки
    // — вместе с заголовком. У слайда они однажды пропали при перестройке
    // формы, и настройка год лежала в базе без способа её задать.
    $sectionForm = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    assert_contains('name="watermark"', $sectionForm);
    assert_contains('data-watermark-group', $sectionForm);
    assert_true(strpos($sectionForm, 'name="watermark_stroke"') === false, 'поле контура вернулось в форму секции');

    // Имена полей у слайда частью печатает помощник $select, поэтому ищем и
    // готовый атрибут, и вызов помощника.
    $slideForm = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/slide_form.php');
    foreach (['name="watermark"', 'name="watermark_size"', 'name="watermark_opacity"',
              "\$select('watermark_x'", "\$select('watermark_y'"] as $field) {
        assert_contains($field, $slideForm, 'в форме слайда нет настройки надписи: ' . $field);
    }
    assert_contains('[watermark]', $slideForm, 'надпись не переводится из формы слайда');

    $adminJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains('data-watermark-group', $adminJs);
});
