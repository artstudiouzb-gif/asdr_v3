<?php

declare(strict_types=1);

use App\Core\DesignSettings;

test('единый выбор основного шрифта нормализует базовые, Google и собственный варианты', function (): void {
    assert_same(
        ['font_style' => 'serif', 'font_google_body' => ''],
        DesignSettings::normalizeBodyFontChoice('style:serif')
    );
    assert_same(
        ['font_style' => 'system', 'font_google_body' => 'inter'],
        DesignSettings::normalizeBodyFontChoice('google:inter')
    );
    assert_same(
        ['font_style' => 'custom', 'font_google_body' => ''],
        DesignSettings::normalizeBodyFontChoice('google:unknown')
    );
});
test('точные размеры принимают только безопасные значения в заданных диапазонах', function (): void {
    assert_same('16.5px', DesignSettings::normalizeFontSize('16,5'));
    assert_same('24px', DesignSettings::normalizeFontSize('24px'));
    assert_same('', DesignSettings::normalizeFontSize('11'));
    assert_same('', DesignSettings::normalizeFontSize('25'));
    assert_same('0px', DesignSettings::normalizeRadius('0'));
    assert_same('12.5px', DesignSettings::normalizeRadius('12.5'));
    assert_same('', DesignSettings::normalizeRadius('49'));
    assert_same('', DesignSettings::normalizeRadius('calc(1px)'));
    assert_same('1.45', DesignSettings::normalizeLineHeight('1,45'));
    assert_same('2', DesignSettings::normalizeLineHeight('2.0'));
    assert_same('', DesignSettings::normalizeLineHeight('0.9'));
    assert_same('', DesignSettings::normalizeLineHeight('2.6'));
    assert_same('', DesignSettings::normalizeLineHeight('16px'));
    assert_same('34px', DesignSettings::normalizeFsSize('34'));
    assert_same('', DesignSettings::normalizeFsSize('7'));
    assert_same('', DesignSettings::normalizeFsSize('97'));
    assert_same('0.08em', DesignSettings::normalizeMetaLetterSpacing('0,08'));
    assert_same('-0.03em', DesignSettings::normalizeMetaLetterSpacing('-.03em'));
    assert_same('', DesignSettings::normalizeMetaLetterSpacing('0.31'));
    assert_same('', DesignSettings::normalizeMetaLetterSpacing('calc(.1em)'));
});

test('форма дизайна объединяет источники шрифта и показывает точные размеры', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/design/index.php');
    assert_true(is_string($view));
    assert_contains('name="font_body_choice"', $view);
    // Подпись группы дополнена предупреждением о внешнем домене, поэтому
    // проверяем начало, а не точный текст.
    assert_contains('optgroup label="Каталог Google Fonts', $view);
    assert_contains('data-custom-font-fields', $view);
    assert_contains('name="font_size_custom"', $view);
    assert_contains('name="line_height_custom"', $view);
    // Поля размеров по элементам рендерятся циклом из TYPO_SIZES.
    assert_contains('DesignSettings::TYPO_SIZES as $fsKey', $view);
    assert_true(isset(
        DesignSettings::TYPO_SIZES['fs_h1'],
        DesignSettings::TYPO_SIZES['fs_h6'],
        DesignSettings::TYPO_SIZES['fs_lead'],
        DesignSettings::TYPO_SIZES['fs_card_title'],
        DesignSettings::TYPO_SIZES['fs_card_text'],
        DesignSettings::TYPO_SIZES['fs_meta'],
        DesignSettings::TYPO_SIZES['fs_menu'],
        DesignSettings::TYPO_SIZES['fs_topbar'],
    ));
    assert_contains('name="radius_custom"', $view);
    assert_contains('name="newsdetail_padding_top"', $view);
    assert_contains('name="newsdetail_padding_bottom"', $view);
    assert_contains('name="meta_letter_spacing_custom"', $view);
    assert_not_contains('<h2 class="design-section__title">Google-шрифты</h2>', $view);
});

test('детальная новость использует управляемые вертикальные отступы и ровную линию метаданных', function (): void {
    $css = theme_css();

    assert_contains('padding-top: var(--newsdetail-padding-top, 0px);', $css);
    assert_contains('padding-bottom: var(--newsdetail-padding-bottom, clamp(32px, 5vw, 72px));', $css);
    // У премиум-макета расстояние от hero до текста задаёт `gap` статьи, а
    // настройка администратора добавляется сверху — как в обычном макете.
    assert_contains('margin-top: var(--newsdetail-padding-top, 0px);', $css);
    // Дата живёт внутри .news-meta, у которой свой боковой отступ: собственный
    // margin-inline у даты сдвигал бы её вдвое дальше заголовка.
    assert_not_contains('.relnews-card__date { margin-inline', $css);
    assert_contains('.relnews-card > .news-meta { margin: 5px 14px 0; }', $css);
    assert_contains('font-family: var(--font-family, inherit);', $css);
    assert_contains('color: var(--gov-ink, #0b1a30);', $css);
    assert_contains('color: var(--on-accent, #ffffff);', $css);
    assert_contains('font-size: var(--font-size-meta, var(--step--3));', $css);
    assert_contains('letter-spacing: var(--meta-letter-spacing, .08em);', $css);
    assert_contains('.act-card__number', DesignSettings::TYPO_SIZES['fs_meta'][1]);
    assert_contains('.act-card__meta', DesignSettings::TYPO_SIZES['fs_meta'][1]);
});

test('точные размеры сохраняются и переопределяют CSS-переменные (БД)', function (): void {
    ensure_test_db();
    DesignSettings::save([
        'font_body_choice' => 'google:inter',
        'font_size_custom' => '17.5',
        'radius_custom' => '13',
        'line_height_custom' => '1.35',
        'fs_h1' => '34',
        'fs_card_text' => '14',
        'fs_menu' => '15',
        'meta_letter_spacing_custom' => '0.06',
    ]);

    // Точный радиус переносится на кнопки только при скруглённой форме:
    // у «капсулы» своя геометрия, и подменять её значением карточек нельзя.
    $css = DesignSettings::cssVariables(['button' => 'rounded'] + DesignSettings::current());
    assert_contains('--base-font-size:17.5px', $css);
    assert_contains('--base-line-height:1.35', $css);
    assert_contains('--font-size-h1:34px', $css);
    assert_contains('--font-size-card-text:14px', $css);
    // Голого «h1» в списке группы больше нет: уровень заголовка задаёт
    // отдельное правило ниже, с исключением компонентных классов.
    assert_contains(':root body h1[class]', $css);
    assert_contains('font-size:var(--font-size-h1) !important;}', $css);
    assert_contains('.stage__text', $css);
    assert_contains('font-size:var(--font-size-card-text) !important;}', $css);
    assert_contains('.site-menu__link{font-size:var(--font-size-menu) !important;}', $css);
    assert_contains('{letter-spacing:var(--meta-letter-spacing) !important;}', $css);
    $themeCss = \App\Core\SiteThemeCss::build([], \App\Core\HeaderConfig::DEFAULTS, false);
    assert_contains('--meta-letter-spacing:0.06em', $themeCss);

    // Сброс, чтобы прогон был идемпотентным и не влиял на другие тесты.
    DesignSettings::save(['fs_h1' => '', 'fs_card_text' => '', 'fs_menu' => '']);
    assert_not_contains('--font-size-h1:34px', DesignSettings::cssVariables(DesignSettings::current()));
    assert_contains('--radius:13px', $css);
    assert_contains('--btn-radius:13px', $css);
    assert_same('inter', (string) \App\Models\Setting::get('design_font_google_body', ''));
    assert_contains('Inter', (string) \App\Models\Setting::get('font_family', ''));

    // Убираем за собой всё, что писали в БД: иначе значения переживают прогон
    // и следующий запуск валит соседние тесты чужим радиусом и шрифтом.
    DesignSettings::save([
        'font_body_choice' => 'style:pt',
        'font_size_custom' => '',
        'radius_custom' => '',
        'line_height_custom' => '',
        'meta_letter_spacing_custom' => '',
    ]);
    \App\Models\Setting::set('design_font_google_body', '');
});

test('редакционные размеры используют переменные из настроек типографики', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/public-editorial-pages.css');

    assert_contains(
        '.block-stages--history .stage__text',
        $css,
        'текст истории должен оставаться отдельным управляемым элементом',
    );
    assert_contains(
        'font-size: var(--font-size-card-text, var(--step--2));',
        $css,
        'размер текста этапа должен приходить из настроек, сохраняя прежний fallback',
    );
    assert_contains('font-size: var(--font-size-card-title, var(--step-0));', $css);
    assert_contains('font-size: var(--font-size-meta, var(--step--3));', $css);
    assert_contains('.profile__name', DesignSettings::TYPO_SIZES['fs_h1'][1]);
    assert_contains(
        'font-family: var(--font-heading, var(--font-family));',
        $css,
        'классовый стиль H1 не должен обходить выбранный шрифт заголовков',
    );
});

test('заголовки карточек новостей настраиваются отдельно от остальных карточек', function (): void {
    $cards = DesignSettings::TYPO_SIZES['fs_card_title'][1];
    $news = DesignSettings::TYPO_SIZES['fs_news_title'][1];
    foreach (['.news-card__title', '.relnews-card__title', '.adjnews__title', '.widget-latest-news__title'] as $selector) {
        assert_contains($selector, $news, "{$selector}: заголовок новости должен слушать свою настройку");
        assert_not_contains($selector, $cards, "{$selector}: заголовок новости не должен слушать размер карточек");
    }

    // Пустое поле означает «как у карточек»: до появления настройки эти
    // заголовки слушались размера карточек, и пустое значение не должно
    // менять размер на уже настроенном сайте.
    \App\Models\Setting::overrideInMemory('design_fs_card_title', '18px');
    \App\Models\Setting::overrideInMemory('design_fs_news_title', '');
    assert_same('18px', DesignSettings::typographySizes()['fs_news_title']);

    \App\Models\Setting::overrideInMemory('design_fs_news_title', '22px');
    assert_same('22px', DesignSettings::typographySizes()['fs_news_title']);
    $css = DesignSettings::typographyCss();
    assert_contains('--font-size-news-title:22px', $css);
    assert_contains('--font-size-card-title:18px', $css);
    assert_contains('.relnews-card__title', $css);

    // Заголовок карточки новости остаётся <h3>/<h2> по структуре страницы,
    // поэтому его класс обязан быть в исключении правила по тегу — иначе
    // уровень заголовка перебил бы собственную настройку.
    \App\Models\Setting::overrideInMemory('design_fs_h3', '24px');
    assert_contains('.relnews-card__title', substr(DesignSettings::typographyCss(), (int) strpos(DesignSettings::typographyCss(), ':root body h3[class]:not(')));

    \App\Models\Setting::overrideInMemory('design_fs_h3', '');
    \App\Models\Setting::overrideInMemory('design_fs_card_title', '');
    \App\Models\Setting::overrideInMemory('design_fs_news_title', '');
});

test('HTML-уровень заголовка главнее компонентного класса', function (): void {
    assert_not_contains('.newsdetail-card__title', DesignSettings::TYPO_SIZES['fs_h2'][1]);
    assert_contains('.newsdetail-card__title', DesignSettings::TYPO_SIZES['fs_h3'][1]);

    \App\Models\Setting::overrideInMemory('design_fs_h2', '32px');
    \App\Models\Setting::overrideInMemory('design_fs_h3', '24px');
    $css = DesignSettings::typographyCss();

    $componentRule = strpos($css, '.newsdetail-card__title');
    // Правило по тегу теперь исключает классы, явно отнесённые к компонентным
    // группам, поэтому между «h3[class]» и запятой стоит :not(…).
    $semanticRule = strpos($css, ':root body h3[class]:not(');
    assert_true($componentRule !== false, 'класс карточки должен быть связан с настройкой H3');
    assert_true($semanticRule !== false, 'для H3 нужна финальная защита от классовых переопределений');
    assert_true($semanticRule > $componentRule, 'семантическое правило H3 должно выводиться последним');

    \App\Models\Setting::overrideInMemory('design_fs_h2', '');
    \App\Models\Setting::overrideInMemory('design_fs_h3', '');
});

test('классы публичных заголовков не привязаны к чужому уровню', function (): void {
    $configuredLevels = [];
    foreach (range(1, 6) as $level) {
        preg_match_all('/\.([a-z][a-z0-9_-]*)/i', DesignSettings::TYPO_SIZES['fs_h' . $level][1], $matches);
        foreach ($matches[1] as $className) {
            $configuredLevels[$className][] = $level;
        }
    }

    $root = dirname(__DIR__, 2);
    foreach ([$root . '/app/Views/site', $root . '/templates'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/<h([1-6])\b[^>]*\bclass=["\']([^"\']+)["\']/i', $source, $headings, PREG_SET_ORDER);
            foreach ($headings as $heading) {
                $actualLevel = (int) $heading[1];
                foreach (preg_split('/\s+/', trim($heading[2])) ?: [] as $className) {
                    if (!isset($configuredLevels[$className])) {
                        continue;
                    }
                    assert_true(
                        in_array($actualLevel, $configuredLevels[$className], true),
                        ".{$className} используется как H{$actualLevel}, но назначен другой настройке",
                    );
                }
            }
        }
    }
});
