<?php

declare(strict_types=1);

use App\Core\DesignSettings;

test('DesignSettings::sanitize отбрасывает неизвестные значения к дефолту', function () {
    assert_same('wide', DesignSettings::sanitize('container', 'wide'));
    // Значение по умолчанию берём из описания опции: оно меняется вместе с
    // оформлением сайта, а проверяем мы откат мусора, а не саму ширину.
    assert_same(
        DesignSettings::OPTIONS['container']['default'],
        DesignSettings::sanitize('container', 'bogus')
    );
    assert_true(DesignSettings::sanitize('nope', 'x') === null);
});

test('DesignSettings::cssVariables формирует корректные переменные', function () {
    // Ручные переопределения (свой радиус, размеры) имеют приоритет над
    // переданными значениями — на время проверки убираем их.
    reset_design_state();
    $css = DesignSettings::cssVariables([
        'container' => 'wide', 'radius' => 'large', 'card_gap' => 'lg', 'density' => 'spacious',
        'button' => 'pill', 'catalog_layout' => 'cards_lg', 'header_style' => 'accent', 'header_sticky' => 'on',
    ]);
    assert_contains('--container-max:1500px', $css);
    assert_contains('--radius:22px', $css);
    assert_contains('--card-gap:32px', $css);
    assert_contains('--btn-radius:999px', $css);
});

test('Отступы страницы новости принимают безопасные значения и сохраняются (БД)', function () {
    ensure_test_db();
    DesignSettings::save([
        'newsdetail_padding_top' => '24',
        'newsdetail_padding_bottom' => '64px',
    ]);

    assert_same('24px', DesignSettings::newsDetailPaddingTop());
    assert_same('64px', DesignSettings::newsDetailPaddingBottom());
    assert_same('0px', DesignSettings::normalizeNewsDetailSpacing('0'));
    assert_same('', DesignSettings::normalizeNewsDetailSpacing('201'));
    assert_same('', DesignSettings::normalizeNewsDetailSpacing('clamp(1px, 2vw, 3px)'));
    $themeCss = \App\Core\SiteThemeCss::build([], \App\Core\HeaderConfig::DEFAULTS, false);
    assert_contains('--newsdetail-padding-top:24px', $themeCss);
    assert_contains('--newsdetail-padding-bottom:64px', $themeCss);

    DesignSettings::save([
        'newsdetail_padding_top' => '',
        'newsdetail_padding_bottom' => '',
    ]);
    $themeCss = \App\Core\SiteThemeCss::build([], \App\Core\HeaderConfig::DEFAULTS, false);
    assert_not_contains('--newsdetail-padding-top:', $themeCss);
    assert_not_contains('--newsdetail-padding-bottom:', $themeCss);
});

test('DesignSettings::bodyClasses отражает глобальный макет без настроек конструктора шапки', function () {
    $on = DesignSettings::bodyClasses([
        'container' => 'standard', 'radius' => 'small', 'card_gap' => 'sm', 'density' => 'standard',
        'button' => 'rounded', 'catalog_layout' => 'list', 'header_style' => 'dark', 'header_sticky' => 'on',
    ]);
    assert_contains('design-catalog-list', $on);
    assert_not_contains('design-header-dark', $on);
    assert_not_contains('design-header-sticky', $on);

    $off = DesignSettings::bodyClasses([
        'container' => 'standard', 'radius' => 'small', 'card_gap' => 'sm', 'density' => 'standard',
        'button' => 'rounded', 'catalog_layout' => 'cards_sm', 'header_style' => 'light', 'header_sticky' => 'off',
    ]);
    assert_not_contains('design-header-sticky', $off);
    assert_contains('design-catalog-cards_sm', $off);
});

test('DesignSettings::bodyClasses включает глобальные компоненты без поиска и футера', function () {
    $cls = DesignSettings::bodyClasses(DesignSettings::PRESETS['modern']['values']);
    assert_contains('design-detail-sidebar', $cls);
    assert_contains('design-cards-elevated', $cls);
    assert_contains('design-sidebar-floating', $cls);
    assert_not_contains('design-search-overlay', $cls);
    assert_not_contains('design-footer-columns', $cls);
    assert_contains('design-mmenu-burger', $cls);

    $min = DesignSettings::bodyClasses(DesignSettings::PRESETS['minimal']['values']);
    assert_contains('design-detail-plain', $min);
    assert_not_contains('design-footer-minimal', $min);
});

test('DesignSettings: кнопка «Наверх» — тумблер даёт/убирает класс design-scrolltop', function () {
    assert_same('on', DesignSettings::sanitize('scroll_top', 'on'));
    assert_same('on', DesignSettings::sanitize('scroll_top', 'bogus')); // default — включена

    $base = DesignSettings::PRESETS['classic']['values'];
    assert_contains('design-scrolltop', DesignSettings::bodyClasses($base)); // в пресетах включена
    assert_contains('design-scrolltop', DesignSettings::bodyClasses(['scroll_top' => 'on'] + $base));
    assert_not_contains('design-scrolltop', DesignSettings::bodyClasses(['scroll_top' => 'off'] + $base));
});

test('DesignSettings::cssVariables задаёт тень карточек по стилю', function () {
    $flat = DesignSettings::cssVariables(DesignSettings::PRESETS['minimal']['values']);
    assert_contains('--card-shadow:none', $flat);
    $elevated = DesignSettings::cssVariables(DesignSettings::PRESETS['modern']['values']);
    assert_contains('--card-shadow:0 10px 30px', $elevated);
});

test('DesignSettings пресеты покрывают все опции валидными значениями', function () {
    foreach (DesignSettings::PRESETS as $name => $preset) {
        foreach (DesignSettings::OPTIONS as $key => $opt) {
            assert_true(isset($preset['values'][$key]), "пресет {$name} задаёт опцию {$key}");
            assert_true(
                isset($opt['choices'][$preset['values'][$key]]),
                "пресет {$name}: значение {$key} допустимо"
            );
        }
    }
});

test('Палитра материализуется в color_primary/color_accent; custom не трогает (БД)', function () {
    ensure_test_db();
    // Иначе выбранный в другом тесте Google-шрифт перебьёт шрифт палитры.
    reset_design_state();
    \App\Models\Setting::set('color_primary', '#010101');
    \App\Models\Setting::set('color_accent', '#020202');

    // Применяем палитру gov_blue — цвета перезаписаны.
    // Ожидание берём из самого определения палитры: проверяется перенос цветов
    // в настройки, а не конкретные оттенки, которые дизайн вправе менять.
    [, $govPrimary, $govAccent] = DesignSettings::PALETTES['gov_blue'];
    DesignSettings::save(['palette' => 'gov_blue', 'font_style' => 'serif']);
    assert_same($govPrimary, \App\Models\Setting::get('color_primary'));
    assert_same($govAccent, \App\Models\Setting::get('color_accent'));
    assert_contains('Georgia', \App\Models\Setting::get('font_family'));

    // Возврат на custom: ставим ручные значения — save их не перетирает.
    \App\Models\Setting::set('color_primary', '#0a0b0c');
    DesignSettings::save(['palette' => 'custom', 'font_style' => 'custom']);
    assert_same('#0a0b0c', \App\Models\Setting::get('color_primary'));
});

test('Каждая палитра пресетов существует и полна', function () {
    foreach (DesignSettings::PRESETS as $name => $preset) {
        $pal = $preset['values']['palette'] ?? null;
        assert_true(isset(DesignSettings::PALETTES[$pal]), "палитра пресета {$name}");
        $font = $preset['values']['font_style'] ?? null;
        assert_true(isset(DesignSettings::FONTS[$font]), "шрифт пресета {$name}");
    }
});

test('Пользовательские конфигурации: сохранить/применить/удалить + снапшот своих цветов (БД)', function () {
    ensure_test_db();
    // Снимок берёт ручные цвета (design_custom_*), а их мог оставить сосед.
    reset_design_state();
    \App\Models\Setting::set('design_user_presets', '');

    // Текущее состояние: палитра custom с ручными цветами.
    DesignSettings::save(['palette' => 'custom', 'font_style' => 'custom', 'container' => 'narrow']);
    \App\Models\Setting::set('color_primary', '#123456');
    \App\Models\Setting::set('color_accent', '#654321');

    $slug = DesignSettings::saveUserPreset('Моя тема');
    assert_true($slug !== null, 'пресет сохранён');
    assert_true(isset(DesignSettings::userPresets()[$slug]), 'в списке');

    // Меняем всё, затем применяем пресет — опции и ручные цвета вернулись.
    DesignSettings::save(['palette' => 'gov_blue', 'container' => 'wide']);
    assert_same(DesignSettings::PALETTES['gov_blue'][1], \App\Models\Setting::get('color_primary'));

    assert_true(DesignSettings::applyPreset('user:' . $slug));
    $cur = DesignSettings::current();
    assert_same('narrow', $cur['container']);
    assert_same('custom', $cur['palette']);
    assert_same('#123456', \App\Models\Setting::get('color_primary'));
    assert_same('#654321', \App\Models\Setting::get('color_accent'));

    // Пустое имя — отказ; удаление работает.
    assert_true(DesignSettings::saveUserPreset('  ') === null);
    assert_true(DesignSettings::deleteUserPreset($slug));
    assert_false(isset(DesignSettings::userPresets()[$slug]));
    assert_false(DesignSettings::applyPreset('user:' . $slug));
});

test('Частичное сохранение не сбрасывает отсутствующие настройки дизайна (БД)', function () {
    ensure_test_db();
    reset_design_state();
    \App\Models\Setting::set('design_radius', 'large');
    \App\Models\Setting::set('design_heading_line_height', 'relaxed');

    DesignSettings::save([
        'palette' => 'custom',
        'color_primary' => '#102030',
    ]);

    $current = DesignSettings::current();
    assert_same('large', $current['radius']);
    assert_same('relaxed', $current['heading_line_height']);
    reset_design_state();
});

test('Пользовательская конфигурация хранит отступы, точный интервал и уникальные Unicode-имена (БД)', function () {
    ensure_test_db();
    reset_design_state();
    $backupPresets = \App\Models\Setting::get('design_user_presets', '');
    \App\Models\Setting::set('design_user_presets', json_encode([]));

    DesignSettings::save([
        'space_small' => '15px',
        'space_premium' => '35px',
        'space_max' => '70px',
        'heading_line_height_custom' => '1.18',
    ]);
    $first = DesignSettings::saveUserPreset('Основная тема');
    $second = DesignSettings::saveUserPreset('Рабочая тема');
    assert_true($first !== null && $second !== null && $first !== $second);

    DesignSettings::save([
        'space_small' => '20px',
        'space_premium' => '40px',
        'space_max' => '80px',
        'heading_line_height_custom' => '1.4',
    ]);
    assert_true(DesignSettings::applyUserPreset((string) $first));
    assert_same('15px', DesignSettings::semanticSpacings()['space_small']);
    assert_same('35px', DesignSettings::semanticSpacings()['space_premium']);
    assert_same('70px', DesignSettings::semanticSpacings()['space_max']);
    assert_same('1.18', DesignSettings::headingLineHeightCustom());

    \App\Models\Setting::set('design_user_presets', (string) $backupPresets);
    reset_design_state();
});

test('Setting::overrideInMemory меняет значение только в памяти, БД не трогает (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set('design_header_style', 'light');

    \App\Models\Setting::overrideInMemory('design_header_style', 'dark');
    assert_same('dark', \App\Models\Setting::get('design_header_style'));

    // В БД осталось сохранённое значение.
    $stmt = \App\Core\Database::pdo()->prepare('SELECT `value` FROM settings WHERE `key` = :k');
    $stmt->execute([':k' => 'design_header_style']);
    assert_same('light', (string) $stmt->fetchColumn());

    // После сброса кэша (любой set) возвращается значение из БД.
    \App\Models\Setting::set('site_name_probe', 'x');
    assert_same('light', \App\Models\Setting::get('design_header_style'));
});

test('Локальный каталог шрифтов: выбранный шрифт имеет приоритет, пусто возвращает базовый стиль', function () {
    ensure_test_db();

    DesignSettings::save([
        'font_style' => 'serif',
        'font_google_heading' => 'lora',
        'font_google_body' => 'inter',
    ]);
    assert_contains('Lora', (string) \App\Models\Setting::get('font_heading', ''));
    assert_contains('Inter', (string) \App\Models\Setting::get('font_family', ''));

    // Сброс: заголовки возвращаются к PT, текст — к карточке serif выше.
    DesignSettings::save([
        'font_style' => 'serif',
        'font_google_heading' => '',
        'font_google_body' => '',
    ]);
    assert_contains('Georgia', (string) \App\Models\Setting::get('font_heading', ''));
    assert_contains('Georgia', (string) \App\Models\Setting::get('font_family', ''));
});

test('Локальный каталог шрифтов: неизвестный slug игнорируется', function () {
    ensure_test_db();
    DesignSettings::save(['font_google_heading' => 'evil-font']);
    assert_same('', (string) \App\Models\Setting::get('design_font_google_heading', ''));
});

test('Метка заголовка секции: вид, толщина и высота приходят из «Дизайна»', function () {
    // Прежде черта перед заголовком была константой в правиле темы (3px,
    // .16em, акцент) — поменять её было нечем, и у центрированного заголовка
    // она висела слева сама по себе.
    $options = (new ReflectionClass(DesignSettings::class))->getConstant('OPTIONS');
    assert_true(isset($options['section_marker']), 'настройка объявлена в «Дизайне»');
    assert_same(['line', 'emblem', 'off'], array_keys($options['section_marker']['choices']));

    // Класс на <body> — потребитель настройки; вид метки читается из него.
    foreach (['line', 'emblem', 'off'] as $marker) {
        assert_contains('design-secmark-' . $marker, DesignSettings::bodyClasses(['section_marker' => $marker]));
    }
    // Значение вне списка не выводит класса-призрака.
    assert_contains('design-secmark-line', DesignSettings::bodyClasses(['section_marker' => 'мусор']));

    // Пустые размеры дают прежний вид: 3px и высота по строке заголовка.
    $css = DesignSettings::cssVariables(DesignSettings::current());
    assert_contains('--section-marker-width:3px', $css);
    assert_contains('--section-marker-height:calc(100% - .32em)', $css);
    assert_contains('--section-marker-emblem:.9em', $css);

    $polish = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');
    // Правило читает переменные, а не числа.
    assert_contains('inline-size: var(--section-marker-width, 3px);', $polish);
    assert_contains('block-size: var(--section-marker-height, calc(100% - .32em));', $polish);
    // Знак — маска эмблемы сайта, а не второй файл с картинкой.
    assert_contains('mask: var(--gov-emblem) center / contain no-repeat;', $polish);
    // Режимный класс обёрнут в :where() — иначе правило весило бы больше
    // компонентных и перебивало бы их (тот же случай, что с design-type-static).
    assert_contains(':where(body.design-secmark-emblem)', $polish);
    assert_contains(':where(body.design-secmark-off)', $polish);
    // У заголовка не у левого края метки нет: она указывает на начало строки.
    assert_contains('.section-head--align-center .section-head__title::before', $polish);
    assert_contains('.section-head--align-right .section-head__title::before', $polish);
});

test('Метка заголовка секции: размеры сохраняются числом и проверяются диапазоном', function () {
    ensure_test_db();

    DesignSettings::save(['section_marker' => 'emblem', 'section_marker_thickness' => '6', 'section_marker_height' => '40']);
    assert_same('emblem', (string) \App\Models\Setting::get('design_section_marker', ''));
    assert_same('6px', DesignSettings::sectionMarkerThickness());
    assert_same('40px', DesignSettings::sectionMarkerHeight());

    $css = DesignSettings::cssVariables(DesignSettings::current());
    assert_contains('--section-marker-width:6px', $css);
    assert_contains('--section-marker-height:40px', $css);
    // Знак квадратный, поэтому его сторону задаёт высота, а не толщина линии.
    assert_contains('--section-marker-emblem:40px', $css);

    // Значение вне диапазона — это подделанная форма, а не «ближайшее
    // допустимое»: возвращаемся к умолчанию темы.
    DesignSettings::save(['section_marker_thickness' => '99', 'section_marker_height' => '1']);
    assert_same('', DesignSettings::sectionMarkerThickness());
    assert_same('', DesignSettings::sectionMarkerHeight());

    reset_design_state();
});
