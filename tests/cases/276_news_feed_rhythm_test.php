<?php

declare(strict_types=1);

use App\Core\NewsFeedRhythm;

/*
 * Ритм ленты новостей: четыре ряда — «обложка плюс две компактные» → ряд из
 * четырёх компактных → «две компактные плюс широкая» → ряд из четырёх
 * компактных.
 *
 * Одинаковые карточки читаются как таблица. Крупная разбивает сетку и
 * показывает анонс, который в компактную не помещается. Отдельной крупной
 * новости над лентой нет: первая карточка цикла и есть главная новость.
 *
 * Главное здесь — арифметика, а не украшение: крупная карточка занимает две
 * ячейки, поэтому цикл из четырнадцати карточек — шестнадцать ячеек, то есть
 * четыре полных ряда при четырёх колонках и восемь при двух. На три колонки
 * цикл не раскладывается, и там ритм выключается в CSS: иначе крупная
 * карточка упирается в последнюю колонку и сетка оставляет дыру.
 */

test('Цикл ритма укладывается в ряды', function () {
    // Две крупные по две ячейки плюс двенадцать компактных.
    assert_same(16, NewsFeedRhythm::CELLS_PER_CYCLE, 'цикл — шестнадцать ячеек');
    assert_same(
        NewsFeedRhythm::CELLS_PER_CYCLE,
        NewsFeedRhythm::CYCLE + count(NewsFeedRhythm::WIDE_POSITIONS),
        'каждая крупная карточка добавляет к циклу лишнюю ячейку'
    );

    $cells = (NewsFeedRhythm::PAGE_SIZE / NewsFeedRhythm::CYCLE) * NewsFeedRhythm::CELLS_PER_CYCLE;
    assert_same(16, $cells, 'страница — ровно один полный цикл, четыре ряда');
    assert_same(14, NewsFeedRhythm::PAGE_SIZE, 'на странице четырнадцать новостей');
    assert_same(0, $cells % 4, 'при четырёх колонках это ровно четыре ряда');
    // Три колонки в список не входят намеренно: цикл на три колонки не
    // раскладывается, поэтому там ритм выключён в CSS (см. .relnews-card--wide).
    foreach ([4, 2, 1] as $columns) {
        assert_same(0, $cells % $columns, "при {$columns} колонках последний ряд остаётся полным");
    }
    assert_same(0, NewsFeedRhythm::PAGE_SIZE % NewsFeedRhythm::CYCLE, 'страница не должна обрывать цикл');
});

test('Крупные карточки стоят по краям цикла и различаются ролью', function () {
    $slots = [];
    for ($i = 0; $i < NewsFeedRhythm::PAGE_SIZE; $i++) {
        $slot = NewsFeedRhythm::slot($i);
        if ($slot !== NewsFeedRhythm::SLOT_COMPACT) {
            $slots[$i] = $slot;
        }
    }

    // 0 — начало первого ряда, 9 — конец третьего (после двух компактных).
    // Второй и четвёртый ряды — четыре компактные карточки.
    assert_same(
        [0 => 'hero', 9 => 'wide'],
        $slots,
        'на странице по одной обложке и одной широкой карточке'
    );
    // Позиции у видов разные (обложка открывает цикл, широкая замыкает), а
    // оформление с недавних пор общее: у обеих фотография во всю карточку и
    // текст поверх. Слоты при этом остаются раздельными — шаблон печатает имя
    // слота классом, и вернуть видам разное устройство можно правкой одного CSS.
    assert_true(NewsFeedRhythm::isWide(0) && NewsFeedRhythm::isWide(9), 'обе крупные занимают две ячейки');
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12, 13] as $compact) {
        assert_same(NewsFeedRhythm::SLOT_COMPACT, NewsFeedRhythm::slot($compact), "карточка {$compact} остаётся компактной");
    }
});

test('Лента выводит широкую карточку с анонсом, а размер страницы берёт из ритма', function () {
    $listing = (string) file_get_contents(APP_ROOT . '/app/Views/site/_news_list.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/NewsController.php');

    assert_contains('NewsFeedRhythm::slot($index)', $listing, 'ритм считает отдельный класс, а не шаблон');
    assert_contains('relnews-card relnews-card--<?= $slot ?>', $listing, 'класс карточки — её слот');
    assert_contains('relnews-card__excerpt', $listing, 'широкая карточка показывает анонс');
    assert_contains('NewsFeedRhythm::PAGE_SIZE', $controller, 'размер страницы задаёт ритм');
});

test('Широкая карточка занимает две ячейки и складывается в одну колонку', function () {
    $css = theme_css();

    // Класс продублирован в селекторе не для красоты: часть темы
    // blocks/news-detail.css подключается после общего бандла и задаёт
    // `.relnews-card { padding: 0 0 14px }`. Модификатору нужен вес выше, иначе
    // нижний отступ карточки оставлял под фотографией белую полосу.
    assert_true(
        (bool) preg_match(
            '/\.relnews-card\.relnews-card--hero,\s*\n\.relnews-card\.relnews-card--wide \{\s*grid-column: span 2;/',
            $css
        ),
        'обе крупные карточки занимают две ячейки, а не всю строку'
    );
    // В одноколоночной сетке `span 2` создал бы вторую колонку и
    // горизонтальную прокрутку — на узком экране растяжение снимается.
    assert_true(
        (bool) preg_match(
            '/@media \(max-width: 560px\) \{\s*\n\s*\.relnews-card\.relnews-card--hero,\s*\n\s*\.relnews-card\.relnews-card--wide \{ grid-column: auto;/',
            $css
        ),
        'на узком экране растяжение снято у обеих'
    );
    assert_contains('.relnews-card--wide .relnews-card__excerpt', $css, 'анонс оформлен у широкой карточки');
    // Три колонки: цикл на три колонки не раскладывается, поэтому ритм там
    // выключается — иначе крупная карточка упирается в последнюю колонку.
    assert_contains('@media (max-width: 1100px) and (min-width: 1001px)', $css, 'на трёх колонках ритм выключен');
});

test('Дата в карточке новости отбита от края наравне с заголовком', function () {
    // Отступ текста задаёт часть темы blocks/news-detail.css: фотография в
    // карточке идёт «в край», поэтому вставку держит текст. Разметок две — в
    // блоке похожих новостей текст лежит прямо в карточке, в ленте /news
    // завёрнут в `.relnews-card__body`. Пока дата отбивалась селектором с `>`,
    // а заголовок — без него, в ленте число стояло вплотную к рамке, а
    // заголовок рядом был отбит на 14px.
    $part = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');
    assert_contains('.relnews-card > .news-meta', $part, 'дата отбита у плоской разметки');
    assert_contains('.relnews-card > .relnews-card__title,', $part, 'заголовок — тем же селектором, что и дата');
    assert_not_contains("\n.relnews-card__title,\n.relnews-card__excerpt { margin-inline", $part, 'вставка не задаётся мимо прямых потомков');

    assert_contains('.relnews-card__body { display: flex;', theme_css(), 'тело карточки описано в теме');
    assert_true(
        (bool) preg_match('/\.relnews-card__body \{[^}]*padding: 4px 14px 0;/', theme_css()),
        'у тела карточки есть боковой отступ — иначе дата упирается в границу'
    );
});

test('Кадры крупных карточек занимают свою площадь целиком', function () {
    $css = theme_css();

    // У обеих крупных карточек фотография и есть карточка: кадр лежит
    // подложкой, поверх него затемнение, иначе белый текст читался бы только
    // на удачном снимке.
    assert_contains(
        ".relnews-card--hero .news-cover,\n.relnews-card--wide .news-cover { position: absolute; inset: 0; min-width: 0; }",
        $css
    );
    assert_contains('.relnews-card--wide .news-cover::after', $css, 'вуаль по низу кадра у обеих');
    assert_contains('.relnews-card--wide .relnews-card__body', $css, 'текст лежит поверх кадра');

    // За контраст отвечает подложка под самим текстом, а не градиент во всю
    // карточку: его плотность считается в процентах от высоты карточки, а
    // высота текста от неё не зависит, и на светлой фотографии заголовок
    // попадал в зону, где затемнения ещё почти нет. Тело прижато к низу
    // `margin-top: auto` — растянутое `flex: 1`, оно закрасило бы весь кадр.
    assert_true(
        (bool) preg_match(
            '/\.relnews-card--wide \.relnews-card__body \{[^}]*margin-top: auto;[^}]*background:\s*\n\s*linear-gradient/',
            $css
        ),
        'подложка объявлена у текста и не растянута на всю карточку'
    );
    // Высота растушёвки и верхний отступ текста — одно число: разъедутся, и
    // граница подложки перестанет совпадать с началом текста.
    assert_contains('--newshero-fade: clamp(56px, 9vw, 84px);', $css, 'растушёвка задана переменной');
    assert_contains('padding: var(--newshero-fade) 14px', $css, 'отступ текста равен высоте растушёвки');
    // Сплошная заливка под текстом: rgba(6,14,28,.88) поверх даже белой
    // фотографии даёт с белым текстом 13:1 при норме 4.5:1.
    assert_contains('rgba(6, 14, 28, .88)', $css, 'подложка сплошная, а не полупрозрачный градиент');
    assert_contains('--newsgrid-gap: 24px;', $css, 'промежуток объявлен один раз — у самой сетки');

    // Часть темы news-feature.css подключается ПОСЛЕ бандла, поэтому её
    // правила для сетки обязаны обходить крупные карточки стороной: при равном
    // весе они побеждали и красили заголовок поверх фотографии в тёмный
    // --gov-title, то есть в цвет, на кадре не читаемый вовсе.
    $feature = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-feature.css');
    foreach (['.relnews-card__title', '.relnews-card__date'] as $target) {
        assert_true(
            !(bool) preg_match(
                '/\.newslist-grid \.relnews-card(?:__| )[^{]*' . preg_quote(ltrim($target, '.'), '/') . '[^{]*\{[^}]*color:/',
                $feature
            ) || str_contains($feature, ':not(.relnews-card--hero):not(.relnews-card--wide)'),
            'цвет ' . $target . ' в сетке не адресован крупным карточкам'
        );
    }
    assert_contains(
        '.newslist-grid .relnews-card:not(.relnews-card--hero):not(.relnews-card--wide) .relnews-card__title',
        $feature,
        'заголовок крупной карточки не перекрашивается частью темы'
    );
    assert_contains(
        '.newslist-grid .relnews-card:not(.relnews-card--hero):not(.relnews-card--wide) .relnews-card__date',
        $feature,
        'дата крупной карточки не перекрашивается частью темы'
    );
});

test('Соседние новости — зеркальная пара', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');
    $css = theme_css();

    // Порядок разметки и порядок колонок должны совпадать: у «предыдущей»
    // стрелка → кадр → текст, у «следующей» — текст → кадр → стрелка. Прежде
    // кадр в обеих карточках стоял слева, вправо уезжал только текст, и пара
    // читалась как две разные карточки.
    $next = substr($view, (int) strpos($view, 'adjnews adjnews--next'));
    $body = strpos($next, 'adjnews__body');
    $media = strpos($next, 'adjnews__media');
    $arrow = strpos($next, 'adjnews__arrow');
    assert_true($body !== false && $media !== false && $arrow !== false, 'карточка «следующая» собрана из трёх частей');
    assert_true($body < $media, 'у «следующей» текст идёт перед кадром');
    assert_true($media < $arrow, 'стрелка замыкает карточку «следующей»');

    assert_contains('.adjnews--next { grid-template-columns: minmax(0, 1fr) auto auto;', $css, 'колонки зеркальны разметке');

    // Колонок в карточке ровно три, поэтому лишняя ячейка ломает всю пару:
    // элементы сдвигаются на одну, и последний уезжает на вторую строку —
    // у «предыдущей» текст оказывался под кадром, у «следующей» стрелка под
    // текстом. Ровно это и происходило вживую: обёртку <picture> растворяет
    // `display: contents`, и её место в сетке занимали ОБА ребёнка — не только
    // <img>, но и <source>, который ничего не рисует. Замерено в Chromium:
    // с обёрткой было два ряда вместо одного.
    $base = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    assert_contains('.media-picture { display: contents; }', $base);
    assert_contains('source { display: none; }', $base, '<source> не занимает ячейку сетки');
    // На узком экране зеркало теряет смысл: обе карточки читаются слева направо.
    assert_contains('.adjnews--next .adjnews__arrow { order: -2; }', $css);
    // Кадр на телефоне уступает место заголовку — иначе на текст остаётся
    // колонка в полтора слова.
    assert_contains('.adjnews__media { display: none; }', $css);
});
