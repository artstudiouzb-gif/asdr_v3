<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\CollageBlockNormalizer;
use App\Core\BlockRenderer;
use App\Core\CollageComposition;
use App\Core\CollageEnsemble;
use App\Core\CollageLayout;
use App\Core\SectionColors;

/*
 * «Коллаж»: ансамбли на четыре–семь элементов и плитка «Видео».
 *
 * Места в ансамбле выбираются по типу элемента: цитате — широкая плитка,
 * числу — малая, кадру — высокая. Раскладка приходит переменными (--ga,
 * --cols, --rows), чтобы узкий вариант мог её отменить без флага приоритета.
 */

/** @param array<string, mixed> $data */
function ensemble_html(array $data): string
{
    $rendered = BlockRenderer::render([
        'id' => 407,
        'type' => 'collage',
        'data' => json_encode(CollageBlockNormalizer::normalize($data), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return (string) $rendered['html'] . "\n" . (string) ($rendered['css'] ?? '');
}

/** @return list<array<string, mixed>> */
function ensemble_items(int $tiles, bool $badge): array
{
    $pool = [
        ['type' => 'photo', 'image' => '/uploads/public/a.jpg', 'alt' => 'Объект'],
        ['type' => 'stat', 'value' => '25K+', 'label' => 'обращений'],
        ['type' => 'quote', 'quote_text' => 'Стратегия — это ежедневная работа с цифрами.', 'author' => 'Директор'],
        ['type' => 'video', 'video' => 'https://youtu.be/aqz-KE-bpKQ', 'video_title' => 'Об Агентстве'],
        ['type' => 'stat', 'value' => '120', 'label' => 'объектов'],
        ['type' => 'info', 'info_title' => 'Приём', 'info_rows' => 'Пн – Пт | 9:00 – 18:00'],
        ['type' => 'photo', 'image' => '/uploads/public/b.jpg'],
    ];
    $items = array_slice($pool, 0, $tiles);
    if ($badge) {
        $items[] = ['type' => 'badge', 'text' => 'Стратегия', 'center' => 'emblem'];
    }

    return $items;
}

/** @return array{0: int, 1: int, 2: int, 3: int} */
function ensemble_rect(string $area): array
{
    $parts = array_map('intval', explode('/', $area));

    return [$parts[0], $parts[1], $parts[2], $parts[3]];
}

test('Ансамбли — композиции по ролям, у каждой есть рисунок в форме', function (): void {
    foreach (CollageEnsemble::LAYOUTS as $layout) {
        assert_true(isset(CollageLayout::LAYOUTS[$layout]), $layout . ' есть в списке типов сборки');
        assert_true(CollageLayout::isComposed($layout), $layout . ' — композиция: полей размещения у элементов нет');
        assert_false(CollageLayout::isPreset($layout), $layout . ' не считается ячейками холста');
    }
    $fields = BlockFieldSchema::fields('collage');
    foreach (CollageEnsemble::LAYOUTS as $layout) {
        assert_true(isset($fields['layout']->variants[$layout]), $layout . ' показан рисунком, а не только словом');
        assert_false(in_array($layout, $fields['ratio']->when['values'] ?? [], true), 'пропорцию ансамблю задаёт раскладка, а не полотно');
    }
});

test('На 1–7 элементов плитки не выходят за сетку и не накрывают друг друга', function (): void {
    $problems = [];
    foreach (['bento', 'stage', 'panorama'] as $layout) {
        for ($n = 1; $n <= CollageEnsemble::CAPACITY; $n++) {
            foreach ([false, true] as $badge) {
                $items = ensemble_items($n, $badge);
                $plan = CollageEnsemble::plan($layout, $items);
                $where = $layout . ' ×' . $n . ($badge ? ' с печатью' : '');
                if (!$plan['complete']) {
                    $problems[] = $where . ': не собралась';
                    continue;
                }
                if (count($plan['tiles']) !== $n) {
                    $problems[] = $where . ': плиток ' . count($plan['tiles']);
                }
                $cols = $layout === 'panorama' ? 14 : 4;
                $cells = [];
                foreach ($plan['tiles'] as $tile) {
                    [$r1, $c1, $r2, $c2] = ensemble_rect($tile['area']);
                    if ($c1 < 1 || $c2 > $cols + 1 || $r1 < 1 || $r2 <= $r1 || $c2 <= $c1) {
                        $problems[] = $where . ': плитка ' . $tile['area'] . ' вне сетки';
                    }
                    // Панорама накрывает кадр карточками намеренно; плитки
                    // между собой не пересекаются нигде.
                    if ($tile['shape'] === 'lead' && $layout === 'panorama') {
                        continue;
                    }
                    for ($r = $r1; $r < $r2; $r++) {
                        for ($c = $c1; $c < $c2; $c++) {
                            if (isset($cells[$r][$c])) {
                                $problems[] = $where . ': плитки пересекаются в ' . $r . ':' . $c;
                            }
                            $cells[$r][$c] = true;
                        }
                    }
                }
                assert_same($badge, $plan['badge'] !== null, $where . ': печать');
            }
        }
    }
    assert_same([], array_values(array_unique($problems)), implode('; ', array_unique($problems)));
});

test('Сетка «Кадр и плитки» и «Кадр в центре» заполнена без дыр', function (): void {
    foreach (['bento', 'stage'] as $layout) {
        for ($n = 2; $n <= CollageEnsemble::CAPACITY; $n++) {
            $plan = CollageEnsemble::plan($layout, ensemble_items($n, false));
            $area = 0;
            $rows = 0;
            foreach ($plan['tiles'] as $tile) {
                [$r1, $c1, $r2, $c2] = ensemble_rect($tile['area']);
                $area += ($r2 - $r1) * ($c2 - $c1);
                $rows = max($rows, $r2 - 1);
            }
            assert_same($rows * 4, $area, $layout . ' ×' . $n . ': пустая ячейка в композиции');
        }
    }
});

test('Ведущий — первый кадр, остальным форма по типу', function (): void {
    $items = [
        ['type' => 'stat', 'value' => '1'],
        ['type' => 'quote', 'quote_text' => 'Цитата'],
        ['type' => 'video', 'video' => 'https://youtu.be/aqz-KE-bpKQ'],
        ['type' => 'photo', 'image' => '/uploads/public/a.jpg'],
    ];
    $plan = CollageEnsemble::plan('bento', $items);
    assert_same(2, $plan['tiles'][0]['index'], 'видео третьим в списке — всё равно ведущий');
    assert_same('lead', $plan['tiles'][0]['shape']);
    $shapes = array_column($plan['tiles'], 'shape', 'index');
    // Второй кадр, стоящий в списке раньше цитаты, не забирает её широкую
    // плитку: расстановка ищет лучшую сумму, а не идёт жадно по списку.
    assert_same('wide', $shapes[1], 'цитате — широкая плитка');
    assert_same('small', $shapes[0], 'числу — малая');
    assert_same([2, 0, 1, 3], array_column($plan['tiles'], 'index'), 'вывод: ведущий первым, остальные в порядке редактора');

    $noMedia = CollageEnsemble::plan('bento', [['type' => 'stat', 'value' => '1'], ['type' => 'quote', 'quote_text' => 'Ц']]);
    assert_true($noMedia['complete'], 'без кадра ансамбль собирается из карточек');
    assert_false(CollageEnsemble::plan('panorama', [['type' => 'stat', 'value' => '1']])['complete'], 'панорама без кадра — баннер, а не композиция');
    assert_contains('фотографии или видео', implode(' ', CollageComposition::problems('panorama', [['type' => 'stat', 'value' => '1']])));

    $many = array_merge(ensemble_items(7, true), [['type' => 'stat', 'value' => '9']]);
    $over = CollageEnsemble::plan('stage', $many);
    assert_same([8], $over['unused'], 'восьмая плитка не помещается — и об этом говорит подсказка');
    assert_contains('1 элемент не поместились', implode(' ', CollageComposition::problems('stage', $many)));
});

test('Печать: у стыка плиток, над кадром или в колонке; текст отходит от неё', function (): void {
    $bento = CollageEnsemble::plan('bento', ensemble_items(5, true));
    assert_same('end', $bento['badge']['at']);
    $by = array_filter(array_column($bento['tiles'], 'by_badge', 'index'));
    assert_true($by !== [] && array_values(array_unique($by)) === ['start'], 'плитки справа от стыка отодвигают текст от печати');

    assert_same('top-center', CollageEnsemble::plan('stage', ensemble_items(5, true))['badge']['at']);
    assert_same('top-end', CollageEnsemble::plan('panorama', ensemble_items(5, true))['badge']['at']);
    assert_same('column', CollageEnsemble::plan('cascade', ensemble_items(5, true))['badge']['at']);
    assert_same(null, CollageEnsemble::plan('bento', ensemble_items(5, false))['badge'], 'без печати композиция просто смыкается');

    $html = ensemble_html(['layout' => 'bento', 'items' => ensemble_items(5, true)]);
    assert_contains('collage-comp__badge collage-comp__badge--end', $html);
    assert_contains('collage-comp--has-badge', $html);
    assert_contains('collage-comp__tile--by-badge-start', $html);
});

test('Каскад: все колонки заняты, низ ровняется перебором', function (): void {
    for ($n = 3; $n <= CollageEnsemble::CAPACITY; $n++) {
        foreach ([false, true] as $badge) {
            $plan = CollageEnsemble::plan('cascade', ensemble_items($n, $badge));
            $columns = array_unique(array_column($plan['tiles'], 'column'));
            sort($columns);
            assert_same([0, 1, 2], $columns, 'каскад ×' . $n . ': пустая колонка');
            assert_same(0, $plan['tiles'][0]['column'], 'композиция начинается с левого верхнего угла');
        }
    }
    $two = CollageEnsemble::plan('cascade', ensemble_items(2, false));
    assert_same([0, 1], array_column($two['tiles'], 'column'), 'у двух элементов две колонки, без пустой посередине');

    $html = ensemble_html(['layout' => 'cascade', 'items' => ensemble_items(6, true)]);
    assert_contains('collage-comp--cascade-3', $html);
    assert_same(3, substr_count($html, 'class="collage-cascade__col"'));
    assert_contains('--ci:', $html, 'порядок редактора возвращается на телефоне через order');
});

test('Раскладка уходит переменными: узкий вариант отменяет её без !important', function (): void {
    $html = ensemble_html(['layout' => 'stage', 'items' => ensemble_items(6, false)]);
    assert_contains('--cols:minmax(0,5fr) minmax(0,7fr) minmax(0,7fr) minmax(0,5fr)', $html);
    assert_contains('--ga:1/2/3/4', $html, 'ведущий — посередине');
    assert_not_contains('grid-area:', $html, 'свойство в scoped CSS весило бы по id');
    $withoutImages = (string) preg_replace('/<img[^>]*>/', '', $html);
    assert_not_contains('style=', $withoutImages, 'инлайн-стилей в блоке быть не должно');

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/collage.css');
    assert_contains('grid-area: var(--ga, auto)', $css);
    assert_contains('@container collage (max-width: 880px)', $css);
    assert_contains('.collage-cascade__col { display: contents; }', $css);
    // Кадр не раздувает ряд своей высотой: замерено 230 → 420px у книжного
    // снимка в малой плитке.
    assert_contains('contain: size', $css);
    assert_not_contains('!important;', $css);
});

test('Видео: YouTube и файл медиатеки, прочее отбрасывается', function (): void {
    $norm = static fn (array $item): ?array => CollageBlockNormalizer::normalize(['items' => [['type' => 'video'] + $item]])['items'][0] ?? null;

    assert_same('https://www.youtube.com/watch?v=aqz-KE-bpKQ', $norm(['video' => 'https://www.youtube.com/shorts/aqz-KE-bpKQ'])['video'], 'ссылка YouTube приводится к одному виду');
    assert_same('/uploads/public/clip.mp4', $norm(['video' => '/uploads/public/clip.mp4'])['video']);
    assert_same(null, $norm(['video' => '/uploads/public/doc.pdf']), 'не ролик — не плитка');
    assert_same(null, $norm(['video' => 'javascript:alert(1)']));
    assert_same(null, $norm([]), 'плитка без ролика не занимает место');
    assert_same('', $norm(['video' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => 'javascript:x'])['poster']);

    $yt = ensemble_html(['layout' => 'free', 'items' => [['type' => 'video', 'video' => 'https://youtu.be/aqz-KE-bpKQ', 'video_title' => 'Об Агентстве']]]);
    // Ролик открывает общий лайтбокс по ссылке YouTube; без скрипта ссылка
    // ведёт на сам ролик. Имя ссылки — действие и предмет.
    assert_contains('href="https://www.youtube.com/watch?v=aqz-KE-bpKQ"', $yt);
    // Типограф ставит неразрывный пробел после короткого предлога.
    assert_contains('aria-label="Смотреть видео: Об' . "\u{00A0}" . 'Агентстве"', $yt);
    assert_contains('i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg', $yt, 'без обложки — превью ролика');
    assert_not_contains('<iframe', $yt, 'плеер не встраивается в страницу');

    $file = ensemble_html(['layout' => 'free', 'items' => [['type' => 'video', 'video' => '/uploads/public/clip.mp4']]]);
    assert_contains('data-lightbox-video', $file);
    assert_contains('preload="metadata"', $file, 'у файла без обложки — первый кадр, а не ролик целиком');

    // Данные из файла шаблона страницы нормализатор не видел: вывод
    // проверяет ролик сам.
    $raw = BlockRenderer::render(['id' => 408, 'type' => 'collage', 'custom_css' => '', 'data' => json_encode([
        'layout' => 'free', 'items' => [['type' => 'video', 'video' => 'javascript:alert(1)', 'col' => 1, 'col_span' => 1, 'row' => 1, 'row_span' => 1, 'shape' => 'rounded']],
    ])]);
    assert_not_contains('javascript:', (string) $raw['html']);

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    assert_contains("a.hasAttribute('data-lightbox-video')", $js, 'файл ролика открывается в лайтбоксе');
    assert_contains('video.src = item.src', $js, 'адрес ставится свойством, а не склейкой разметки');
    assert_true(in_array('.collage__item--video', SectionColors::OWN_COLORS, true), 'подпись ролика не перекрашивается цветом секции');

    $admin = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    assert_contains("video: ['shape', 'video']", $admin, 'форма показывает поля ролика');
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    assert_contains('data-media-type="video"', $form);
    assert_contains("\$p('video_title')", $form);
});

test('Видео — кадр и в прежних композициях', function (): void {
    $roles = CollageComposition::roles('callout', [['type' => 'stat'], ['type' => 'video']]);
    assert_same([1], $roles['slots']['photo'], 'видео встаёт кадром, как фотография');
    $pair = CollageComposition::roles('pair', [['type' => 'photo'], ['type' => 'video']]);
    assert_true(CollageComposition::complete('pair', $pair), 'второй кадр — видео');
});

test('Коллаж без текста держит сетку в колонке конструктора', function (): void {
    // В половине ряда колонок блок уже 880px, и «Кадр и плитки» из одних
    // снимков складывался в галерею: ведущий во всю ширину, плитки по две.
    // Узкий вариант нужен числу и цитате, кадру малая плитка не мешает.
    $photos = [
        ['type' => 'photo', 'image' => '/uploads/public/a.jpg'],
        ['type' => 'photo', 'image' => '/uploads/public/b.jpg'],
        ['type' => 'video', 'video' => 'https://youtu.be/aqz-KE-bpKQ'],
        ['type' => 'badge', 'text' => 'Стратегия'],
    ];
    assert_true(!CollageLayout::hasText($photos));
    assert_true(CollageLayout::hasText([...$photos, ['type' => 'stat', 'value' => '1 200']]));

    assert_contains('collage-comp--textless', ensemble_html(['layout' => 'bento', 'items' => $photos]));
    assert_not_contains('collage-comp--textless', ensemble_html(['layout' => 'bento', 'items' => ensemble_items(5, true)]));
    assert_contains('collage__canvas--textless', ensemble_html(['layout' => 'mosaic', 'items' => $photos]));

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/collage.css');
    assert_contains('@container collage (min-width: 400px) and (max-width: 880px)', $css);
    assert_contains('.collage-comp--grid.collage-comp--textless > .collage__item.collage__item { grid-area: var(--ga, auto);', $css);
    assert_contains('@container cms-col (min-width: 360px) and (max-width: 560px)', $css);
    // Возврат идёт после узкого варианта, иначе при равном весе проиграл бы.
    assert_true(strpos($css, 'collage-comp--textless {') > strpos($css, '@container collage (max-width: 880px)'));
});
