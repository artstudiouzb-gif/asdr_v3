<?php

declare(strict_types=1);

use App\Core\BlockBackground;
use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\CollageBlockNormalizer;
use App\Core\BlockRenderer;
use App\Core\CollageComposition;
use App\Core\CollageLayout;

/*
 * «Коллаж»: композиции по ролям («Кадр и выноска», «Шахматка», «Портрет и
 * слово»), центр печати и свой узор.
 *
 * Композиции не укладываются в ячейки: карточка стоит в вырезе снимка,
 * печать — на его краю. Поэтому роль элемента выводится из его типа, а
 * отношения между элементами задаёт CSS.
 */

/** @param array<string, mixed> $data */
function collage_html(array $data): string
{
    $rendered = BlockRenderer::render([
        'id' => 400,
        'type' => 'collage',
        'data' => json_encode(CollageBlockNormalizer::normalize($data), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) $rendered['html'] . "\n" . (string) ($rendered['css'] ?? '');
}

test('Композиции объявлены типами сборки и не выдают себя за пресеты ячеек', function (): void {
    foreach (CollageLayout::COMPOSED as $layout) {
        assert_true(isset(CollageLayout::LAYOUTS[$layout]), $layout . ' есть в списке типов сборки');
        assert_true(CollageLayout::isComposed($layout));
        // Пресет ячеек обязан раскладываться в холст схемы (тест 379), а
        // композиции ячеек не знают — смешивать их нельзя.
        assert_false(CollageLayout::isPreset($layout), $layout . ' — не пресет ячеек');
    }

    $fields = BlockFieldSchema::fields('collage');
    assert_same(['field' => 'layout', 'values' => ['callout']], $fields['cut_corner']->when, 'угол выреза — только у «Кадра и выноски»');
    assert_false(in_array('checker', $fields['ratio']->when['values'] ?? [], true), 'у шахматки пропорция своя');
    assert_true((bool) $fields['badge_spin']->default, 'печать вращалась всегда — умолчание не меняет вида');
});

test('Роль элемента выводится из типа; лишнее называется подсказкой', function (): void {
    $items = [
        ['type' => 'stat'], ['type' => 'photo'], ['type' => 'photo'],
        ['type' => 'badge'], ['type' => 'quote'], ['type' => 'pattern'],
    ];
    $roles = CollageComposition::roles('callout', $items);
    assert_same([1], $roles['slots']['photo'], 'кадр — первая фотография');
    assert_same([0], $roles['slots']['card'], 'карточка — первый показатель или цитата');
    assert_same([3], $roles['slots']['badge']);
    assert_same([5], $roles['slots']['pattern']);
    assert_same([2, 4], $roles['unused']);

    $problems = implode(' ', CollageComposition::problems('callout', $items));
    assert_contains('2 элемента не нашли места', $problems);

    $noPhoto = implode(' ', CollageComposition::problems('portrait', [['type' => 'quote']]));
    assert_contains('фотографии', $noPhoto, 'без кадра композиция не строится — редактору говорится прямо');

    // Шахматка показывает все; печать уходит на стык только от четырёх клеток.
    $checker = CollageComposition::roles('checker', [['type' => 'photo'], ['type' => 'badge'], ['type' => 'stat'], ['type' => 'photo'], ['type' => 'quote']]);
    assert_same([1], $checker['slots']['badge']);
    assert_same([0, 2, 3, 4], $checker['slots']['tiles']);
    $small = CollageComposition::roles('checker', [['type' => 'photo'], ['type' => 'badge']]);
    assert_same([], $small['slots']['badge'], 'без стыка печать — обычная клетка');
    assert_same([0, 1], $small['slots']['tiles']);
});

test('Кадр и выноска: вырез маской, угол из настройки, узор не выходит за край', function (): void {
    $html = collage_html([
        'layout' => 'callout',
        'cut_corner' => 'tr',
        'items' => [
            ['type' => 'photo', 'image' => '/uploads/public/test.jpg', 'alt' => 'Кадр'],
            ['type' => 'stat', 'value' => '120', 'label' => 'объектов'],
            ['type' => 'badge', 'text' => 'Стратегия', 'center' => 'emblem'],
        ],
    ]);
    assert_contains('collage-comp--callout', $html);
    assert_contains('collage-comp--cut-tr', $html);
    assert_contains('collage-comp__photo', $html);
    assert_contains('collage-comp__card', $html);
    assert_not_contains('grid-column', $html, 'у композиции нет ячеек');

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/collage.css');
    // Вырез — дыра в самом снимке, а не обводка цветом фона: на фото-фоне
    // секции обводка была бы видна чужим пятном.
    assert_contains('mask-composite: subtract', $css);
    assert_contains('-webkit-mask-composite: source-out', $css);
    assert_not_contains('right: -4%', $css, 'узор за краем блока давал странице прокрутку вбок');
    // Движение одно, по прокрутке, и гаснет при «меньше движения».
    assert_contains('animation-timeline: view()', $css);
    assert_true(
        (bool) preg_match('/prefers-reduced-motion: no-preference\)\s*\{\s*\.collage-comp--callout > \.collage-comp__card/', $css),
        'въезд карточки объявлен только при no-preference'
    );
});

test('Центр печати: эмблема, значок, своя картинка; старые печати не меняются', function (): void {
    $norm = static fn (array $badge): array => CollageBlockNormalizer::normalize(['items' => [['type' => 'badge', 'text' => 'Печать'] + $badge]])['items'][0];

    assert_same('icon', $norm(['icon_svg' => 'star'])['center'], 'печать со значком, собранная раньше, показывает значок');
    assert_same('none', $norm([])['center'], 'печать без значка остаётся одной надписью');
    assert_same('emblem', $norm(['center' => 'emblem'])['center']);
    assert_same('none', $norm(['center' => 'image'])['center'], 'картинка без файла — не пустой кружок');
    assert_same('image', $norm(['center' => 'image', 'center_image' => '/uploads/public/logo.png'])['center']);

    $html = collage_html(['layout' => 'free', 'items' => [['type' => 'badge', 'text' => 'Приёмная', 'center' => 'emblem']]]);
    assert_contains('collage__badge-emblem', $html);
    // Адрес маски абсолютный: относительный из файла блока разрешился бы не туда.
    assert_contains('mask-image:url("' . BlockBackground::emblemUrl() . '")', $html);

    $logo = collage_html(['layout' => 'free', 'items' => [['type' => 'badge', 'text' => 'Приёмная', 'center' => 'image', 'center_image' => '/uploads/public/logo.png']]]);
    assert_contains('class="collage__badge-logo" src="/uploads/public/logo.png" alt=""', $logo);

    $still = collage_html(['layout' => 'free', 'badge_spin' => false, 'items' => [['type' => 'badge', 'text' => 'Печать']]]);
    assert_contains('block-collage--still', $still);
});

test('Узоры: один список на все формы, свой узор — картинка-плитка', function (): void {
    assert_same(BlockBackground::PATTERNS, array_keys(BlockBackground::PATTERN_LABELS), 'у каждого узора есть подпись');
    foreach (['rings', 'waves'] as $key) {
        assert_true(in_array($key, BlockBackground::PATTERNS, true));
        assert_not_contains('radial-gradient(var(--block-pattern-ink) 1.5px', BlockBackground::patternCss($key), $key . ' не откатывается на точки');
    }
    foreach (['app/Views/admin/footer/index.php', 'app/Views/admin/pages/block_form.php'] as $file) {
        assert_not_contains("'diagonal' => 'Диагональ'", (string) file_get_contents(APP_ROOT . '/' . $file), $file . ' держит свою копию подписей');
    }

    $norm = static fn (array $item): array => CollageBlockNormalizer::normalize(['items' => [['type' => 'pattern'] + $item]])['items'][0];
    assert_same('dots', $norm(['pattern' => 'image'])['pattern'], 'свой узор без картинки рисуется точками, а не пустотой');
    $custom = $norm(['pattern' => 'image', 'pattern_image' => '/uploads/public/tile.svg', 'pattern_size' => 'large']);
    assert_same('image', $custom['pattern']);
    assert_same('large', $custom['pattern_size']);

    $html = collage_html(['layout' => 'free', 'items' => [['type' => 'pattern', 'pattern' => 'image', 'pattern_image' => '/uploads/public/tile.svg', 'pattern_size' => 'large']]]);
    assert_contains('background-image:url("/uploads/public/tile.svg")', $html);
});

test('Форма коллажа предлагает центр печати и свой узор', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    foreach (["\$p('center')", "\$p('center_image')", "\$p('pattern_image')", "\$p('pattern_size')"] as $needle) {
        assert_contains($needle, $form);
    }
    // Значок у печати выводился, но задать его было негде: поле иконки
    // стояло только в группе показателя.
    assert_contains("\$p('icon_svg'), (string) (\$item['icon_svg'] ?? ''), ['label' => 'Значок в центре']", $form);
});
