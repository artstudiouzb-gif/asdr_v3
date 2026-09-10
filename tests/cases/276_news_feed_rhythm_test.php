<?php

declare(strict_types=1);

use App\Core\DesignSettings;
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
    // Разметка карточки лежит в партиале: мест вывода у ритма два — лента и
    // мозаика блока подборки, — и вторая копия разъехалась бы с первой.
    $card = (string) file_get_contents(APP_ROOT . '/app/Views/site/_news_rhythm_card.php');
    $feature = (string) file_get_contents(APP_ROOT . '/templates/blocks/news_feature.php');

    assert_contains('NewsFeedRhythm::slot($index)', $listing, 'ритм считает отдельный класс, а не шаблон');
    assert_contains('relnews-card relnews-card--<?= $slot ?>', $card, 'класс карточки — её слот');
    assert_contains('relnews-card__excerpt', $card, 'широкая карточка показывает анонс');
    assert_contains('NewsFeedRhythm::PAGE_SIZE', $controller, 'размер страницы задаёт ритм');

    // Обе стороны берут одну разметку, а не пишут свою.
    assert_contains('_news_rhythm_card.php', $listing, 'лента печатает карточку общим партиалом');
    assert_contains('_news_rhythm_card.php', $feature, 'мозаика печатает карточку тем же партиалом');
    assert_contains('NewsFeedRhythm::blockSlot(', $feature, 'ритм мозаики считает тот же класс');
});

test('Мозаика блока набирается ритмом и не оставляет ряд без обложек', function () {
    $feature = (string) file_get_contents(APP_ROOT . '/templates/blocks/news_feature.php');
    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/BlockRenderer.php');

    // Шесть карточек и восемь ячеек: 8 делится на 4 и на 2 колонки, 6 — на 3 и
    // на 1, а на трёх колонках растяжение выключено. Ряд без дыры получается
    // на каждой ширине; иное число оборвало бы последний ряд на середине.
    assert_same(6, NewsFeedRhythm::BLOCK_SIZE, 'мозаика — шесть материалов');
    $cells = 0;
    foreach (range(0, NewsFeedRhythm::BLOCK_SIZE - 1) as $i) {
        $cells += NewsFeedRhythm::blockSlot($i) === NewsFeedRhythm::SLOT_COMPACT ? 1 : 2;
    }
    assert_same(8, $cells, 'ячеек ровно восемь');
    assert_same(NewsFeedRhythm::SLOT_HERO, NewsFeedRhythm::blockSlot(0), 'обложка открывает первый ряд');
    assert_same(NewsFeedRhythm::SLOT_WIDE, NewsFeedRhythm::blockSlot(5), 'широкая замыкает второй');

    // Лимит берётся из ритма, а не литералом: разъедутся — ряд оборвётся.
    assert_contains('NewsFeedRhythm::BLOCK_SIZE', $renderer, 'лимит мозаики задаёт ритм');
    // Прежнее семейство карточек мозаики выводило нижний ряд без обложек.
    // Сверяем атрибут, а не файл целиком: имена старых классов законно
    // упоминаются в комментарии рядом, и проверка по голому имени держалась бы
    // на том, как в нём переносится строка.
    assert_not_contains('class="newsfeat-text"', $feature, 'карточек без обложки в мозаике не осталось');
    assert_not_contains('class="newsfeat-mini"', $feature, 'своего семейства карточек у мозаики больше нет');
    assert_not_contains('class="newsfeat-lead"', $feature, 'крупная карточка мозаики — из ритма, а не своя');
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
    // Сброс растяжения обязан стоять здесь же. public-layout-polish.css
    // объявляет всем карточкам `flex: 1 1 auto` и грузится после темы, а
    // автоотступ забирает свободное место, которого при `flex-grow: 1` не
    // остаётся: `margin-top: auto` вычислялся в 0, тело занимало 99% карточки,
    // заголовок стоял у его верха, а сплошная заливка закрывала кадр целиком.
    assert_true(
        (bool) preg_match(
            '/\.relnews-card--wide \.relnews-card__body \{[^}]*flex: 0 0 auto;[^}]*margin-top: auto;/',
            $css
        ),
        'тело карточки не растягивается — иначе автоотступ не прижмёт его к низу'
    );
    // Высота растушёвки и верхний отступ текста — одно число: разъедутся, и
    // граница подложки перестанет совпадать с началом текста.
    // Величину не закрепляем числом — это настройка вида, и пин превращал бы
    // сторожа в детектор правок. Стережём условие: растушёвка объявлена одним
    // числом, и тем же числом задан верхний отступ текста.
    assert_true(
        (bool) preg_match('/--newshero-fade: clamp\([^;]+\);/', $css),
        'растушёвка задана переменной'
    );
    assert_contains('padding: var(--newshero-fade) 14px', $css, 'отступ текста равен высоте растушёвки');
    // Цвет и плотность подложки — настройки «Дизайна», а не литерал: на
    // тёплой или светлой палитре холодная почти-чёрная вуаль читается чужой.
    // Тема обязана читать их, и запасное значение обязано быть прежним —
    // иначе сайт без настройки поменял бы вид.
    assert_contains('var(--newshero-veil-rgb, 6, 14, 28)', $css, 'цвет подложки приходит настройкой');
    assert_contains('var(--newshero-veil-alpha, .88)', $css, 'плотность подложки приходит настройкой');
    assert_same('#060e1c', DesignSettings::VEIL_COLOR_DEFAULT, 'умолчание цвета — прежний rgb(6, 14, 28)');
    assert_true(abs(DesignSettings::VEIL_ALPHA_BASE - 0.88) < 1e-9, 'умолчание плотности — прежние .88');

    // И главное: подложка существует ради читаемости заголовка на любом
    // кадре. Считаем контраст сами, а не через newsVeil(), чтобы проверка не
    // повторяла проверяемый код: подложка умолчания на самой светлой
    // фотографии обязана давать белому заголовку не меньше 4.5:1.
    $onWhite = \App\Core\AccentContrast::toHex(array_map(
        static fn (int $c): int => (int) round(0.88 * $c + 0.12 * 255),
        \App\Core\AccentContrast::toRgb(DesignSettings::VEIL_COLOR_DEFAULT)
    ));
    assert_true(
        \App\Core\AccentContrast::ratio('#ffffff', $onWhite) >= 4.5,
        'подложка умолчания держит норму контраста и на белой фотографии'
    );
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
