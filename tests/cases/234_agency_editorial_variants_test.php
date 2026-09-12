<?php

declare(strict_types=1);

use App\Core\BlockTypeRegistry;
use App\Core\DesignSettings;
use App\Controllers\Admin\BlockController;

test('Редакционные варианты страницы Агентства являются настройками системных блоков', function (): void {
    $defaults = BlockTypeRegistry::defaults();
    assert_true(array_key_exists('variant', $defaults['text']));
    assert_true(array_key_exists('aside_title', $defaults['text']));
    assert_true(array_key_exists('items', $defaults['text']));
    assert_true(array_key_exists('quote', $defaults['text']));
    assert_true(array_key_exists('media_type', $defaults['text']));
    assert_true(array_key_exists('media_image', $defaults['text']));
    assert_true(array_key_exists('media_video', $defaults['text']));
    assert_true(array_key_exists('media_youtube', $defaults['text']));
    assert_true(array_key_exists('variant', $defaults['stages']));
    foreach (['cards_grid', 'stages'] as $type) {
        assert_true(array_key_exists('description', $defaults[$type]), "{$type}: нет описания раздела");
    }
    assert_true(array_key_exists('career_title', $defaults['bio_education']));
    assert_true(array_key_exists('widgets_before', $defaults['bio_education']));
    assert_true(array_key_exists('widgets_after', $defaults['bio_education']));

    // Редактор — это форма плюс поля, которые рисует схема: у типов со схемой
    // варианты объявлены там, а не в разметке формы.
    $editor = block_editor_markup();
    // «indexed» больше не вариант: нумерация карточек стала настройкой, потому
    // что вариант ничего не менял — номер печатался во всех.
    foreach (['intro', 'system', 'spotlight', 'history', 'acts-editorial', 'media_image', 'media_video', 'media_youtube'] as $variant) {
        assert_contains($variant, $editor, "вариант {$variant} недоступен в редакторе");
    }
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    assert_contains("['key' => 'widgets_before'", $form);
    assert_contains("['key' => 'widgets_after'", $form);
    assert_contains('name="<?= htmlspecialchars($slotKey, ENT_QUOTES) ?>[', $form);
    assert_contains('data-repeater-move="up"', $form);
});

test('Редактор bio_education сохраняет порядок виджетов и удаляет дубли между слотами', function (): void {
    $oldPost = $_POST;
    $_POST = [
        'bio_title' => 'Биография',
        'widgets_before' => ['7', '0', ['bad'], '7', '8'],
        'widgets_after' => ['8', '9', '9', '-2'],
    ];

    try {
        $method = new ReflectionMethod(BlockController::class, 'collectData');
        $data = $method->invoke(new BlockController(), 'bio_education', 'ru');
        assert_same([7, 8], $data['widgets_before']);
        assert_same([9], $data['widgets_after']);
    } finally {
        $_POST = $oldPost;
    }
});

test('Миграция включает варианты без замены редакторского текста', function (): void {
    $sql = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_08_09_agency_editorial_block_variants.sql');
    assert_contains('-- @post-schema', $sql);
    assert_contains("p.slug = 'o-nas'", $sql);
    assert_contains("'$.variant', 'intro'", $sql);
    assert_contains("'$.variant', 'system'", $sql);
    assert_contains("'$.variant', 'spotlight'", $sql);
    assert_contains("'$.variant', 'acts-editorial'", $sql);
    assert_contains("'$.career_title', 'Профессиональный путь'", $sql);
    assert_not_contains("'$.content'", $sql, 'миграция не должна перезаписывать ручные правки текста');
});

test('Редакционные стили не меняют шапку и подвал', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    assert_contains('.block-text--system', $css);
    assert_contains('.cards-grid', $css);
    assert_contains('.block-stages--history', $css);
    assert_contains('.block-docslist--acts-editorial', $css);
    assert_contains('.block-stages--history .stage::after', $css, 'в истории должна быть отключена дублирующая линия');
    assert_not_contains('.site-header', $css);
    assert_not_contains('.site-footer', $css);
});

test('Последний абзац заголовочного блока не создаёт лишний зазор между разделами', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    assert_contains(
        '.cms-block--text:has(.block-text__title) .block-text__content p:last-child',
        $css,
    );
    assert_contains('margin-bottom: 0;', $css);
});

test('Преимущества, этапы и таймлайн имеют собственное описание раздела', function (): void {
    $editor = block_editor_markup();
    assert_contains('name="description"', $editor);
    // Пояснение одно и то же у всех трёх — у двух оно приходит из схемы, у
    // таймлайна пока из рукописной ветки формы.
    assert_contains('отдельный текстовый блок не нужен', $editor);
    foreach (['cards_grid', 'stages'] as $type) {
        $fields = \App\Core\BlockData\BlockFieldSchema::fields($type);
        assert_true(isset($fields['description']), "{$type}: описание не описано схемой");
    }

    foreach (['cards_grid', 'stages'] as $type) {
        $template = (string) file_get_contents(APP_ROOT . '/templates/blocks/' . $type . '.php');
        assert_contains("\$data['description']", $template, "{$type}: описание не выводится");
    }
});

test('Иконки cards_grid настраиваются в редакторе так же, как у счётчиков', function (): void {
    // Настройка объявляется один раз — в схеме полей: прежде эти пять полей
    // дорисовывал скрипт в подвале админки по id «cards_variant», которого у
    // схемной формы нет, и редактор не видел их ни разу.
    $fields = \App\Core\BlockData\BlockFieldSchema::fields('cards_grid');
    foreach (['card_style', 'card_gap', 'icon_size', 'icon_bg', 'icon_position', 'text_align'] as $key) {
        assert_true(isset($fields[$key]), "cards_grid: настройка {$key} не описана схемой");
    }
    assert_same(['field' => 'card_style', 'values' => ['new']], $fields['card_gap']->when);
    foreach (['card_style', 'icon_size', 'icon_bg', 'icon_position', 'text_align'] as $key) {
        assert_same(['field' => 'variant', 'values' => ['icon']], $fields[$key]->when, "cards_grid: {$key} показывается не только у варианта с иконками");
    }
    assert_same(0, $fields['card_gap']->default);
    assert_same(22, $fields['icon_size']->default);
    assert_same('top', $fields['icon_position']->default);

    $editor = block_editor_markup();
    foreach (['card_gap', 'icon_size', 'icon_bg', 'icon_position'] as $key) {
        assert_contains('id="bf_' . $key . '"', $editor, "cards_grid: поля {$key} нет в форме блока");
    }
    assert_contains('Справа от текста', $editor);

    // Прежнее место этих настроек — рукописный скрипт в подвале админки.
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/footer.php');
    assert_not_contains('cards_icon_position', $footer);
    assert_not_contains('_cards_style', $footer);
});

test('Заголовки редакционных разделов используют единый акцентный маркер', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');

    // Маркер и оформление адресованы компоненту, который рисует заголовок.
    assert_contains('.cms-block .section-head__title::before', $css, 'нет общего маркера у шапки секции');
    assert_contains('.block-text__title::before', $css, 'у блока «Текст» свой заголовок, ему маркер нужен отдельно');
    assert_contains('font-size: var(--font-size-h2', $css);

    $designSettings = (string) file_get_contents(APP_ROOT . '/app/Core/DesignSettings.php');
    assert_contains('.block-timeline__title', $designSettings, 'таймлайн должен брать H2 из настроек типографики');
});

test('Оформление заголовка не перечисляет типы блоков поимённо', function (): void {
    // Перечень типов отстаёт от кода молча: он писался, когда общую шапку
    // звали три блока, а зовут её четырнадцать — одиннадцать оформления не
    // получали вовсе, и на одной странице у соседних секций расходились
    // трекинг, интерлиньяж и ширина строки. Правило принадлежит компоненту,
    // поэтому адресовать его отдельным типам нельзя: следующий блок на общей
    // шапке снова остался бы за бортом, и узнали бы об этом не скоро.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    assert_true(
        preg_match('/\.cms-block--[a-z_]+ \.section-head__title/', $css) !== 1,
        'заголовок шапки секции оформлен по типу блока — адресуйте его .cms-block .section-head__title'
    );
});

test('Метка заголовка на редакционной странице слушает настройку', function (): void {
    // Файл грузится после public-layout-polish.css и перебивает его правило,
    // поэтому число здесь молча отменяло бы «Метку заголовка секции».
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    assert_contains('width: var(--section-marker-width', $css, 'толщина метки задана числом мимо настройки');
    assert_contains('letter-spacing: var(--heading-letter-spacing', $css, 'трекинг задан числом мимо настройки');
});

test('Миграция объединяет старые вступления с целевыми блоками без потери текста', function (): void {
    $sql = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_08_10_block_section_descriptions.sql');
    assert_contains('-- @post-schema', $sql);
    assert_contains("'$.description'", $sql);
    assert_contains('JSON_EXTRACT(intro.data', $sql);
    assert_contains('DELETE intro', $sql);
    assert_contains("target.type IN ('advantages', 'stages')", $sql);
});

test('Карьера в блоке «Биография и образование» читается как хронология', function (): void {
    // Страницы руководителей собираются в админке, поэтому проверять фикстуру
    // тут больше нечем; сам приём — линия с маркерами — принадлежит блоку.
    $template = (string) file_get_contents(APP_ROOT . '/templates/blocks/bio_education.php');
    assert_contains('bio-career__title', $template);
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    assert_contains('bio-career__item:last-child', $css);
    assert_contains('background: transparent;', $css);
    assert_contains(':root[data-theme="dark"] .editorial-page__content .bio-career__item::before', $css);
    assert_contains('border-color: color-mix(in srgb, var(--gov-teal) 72%, #fff);', $css);
    assert_contains('left: -1px', $css, 'маркеры карьеры должны быть центрированы по линии');
    assert_contains('box-sizing: border-box', $css, 'граница должна входить в размер маркера');
});

test('Карточки, контакты и правовые акты используют один feature-card', function (): void {
    // «Преимущества» печатали ту же карточку из тех же полей, что «Карточки», —
    // и разъехались с ними настройками и оформлением. Теперь блок один, а
    // копия его правил (включая анимацию иконки, которую тема всё равно гасила)
    // ушла вместе с типом.
    $cards = (string) file_get_contents(APP_ROOT . '/templates/blocks/cards_grid.php');
    $contacts = (string) file_get_contents(APP_ROOT . '/templates/blocks/contact_cards.php');
    $acts = (string) file_get_contents(APP_ROOT . '/templates/blocks/partials/act_card.php');
    assert_false(is_file(APP_ROOT . '/templates/blocks/advantages.php'), 'шаблона «Преимуществ» не осталось');
    assert_contains('feature-card__icon', $cards);
    assert_contains('feature-card contact-card', $contacts);
    assert_contains('feature-card act-card', $acts);
    assert_contains('feature-card__num', $cards);
    assert_contains('feature-card__num', $contacts);
    assert_contains('feature-card__title act-card__number', $acts);
    assert_contains('<p class="feature-card__text act-card__desc">', $acts);
    assert_not_contains('act-card__title', $acts);

    $cardTextSelectors = DesignSettings::TYPO_SIZES['fs_card_text'][1];
    $cardTitleSelectors = DesignSettings::TYPO_SIZES['fs_card_title'][1];
    assert_contains('.act-card__desc', $cardTextSelectors);
    assert_not_contains('.act-card__desc', $cardTitleSelectors);

    $css = theme_css();
    assert_contains('.feature-card.contact-card', $css);
    assert_contains('.feature-card.act-card', $css);
    assert_contains('.feature-card.act-card .act-card__desc', $css);
    assert_contains('font-size: var(--font-size-card-text, var(--step--1))', $css);
    assert_contains('--feature-card-motion-duration: .62s', $css);

    $editorial = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    assert_contains('.anim-card:not(.feature-card)', $editorial);
    assert_contains('.block-docslist--acts-editorial .act-card__desc', $editorial);

    // Мёртвых правил «карточка без класса feature-card» не осталось: класс
    // висел на ней всегда, и такое правило не срабатывало ни разу.
    $layout = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-layout-polish.css');
    assert_not_contains(':not(.feature-card) .block-advantages__icon', $layout);
});

test('Вводный блок Агентства имеет управляемую медиаколонку и безопасную заглушку', function (): void {
    $base = [
        'id' => 23401,
        'type' => 'text',
        'custom_css' => null,
    ];

    $placeholder = \App\Core\BlockRenderer::render($base + ['data' => json_encode([
        'variant' => 'intro',
        'content' => '<p>О работе Агентства</p>',
        'media_type' => 'none',
    ], JSON_UNESCAPED_UNICODE)])['html'];
    assert_contains('block-text__intro-copy', $placeholder);
    assert_contains('block-text__media--placeholder', $placeholder);

    $image = \App\Core\BlockRenderer::render($base + ['data' => json_encode([
        'variant' => 'intro',
        'content' => '<p>О работе Агентства</p>',
        'media_type' => 'image',
        'media_image' => '/uploads/public/about.jpg',
        'media_alt' => 'Рабочая встреча',
    ], JSON_UNESCAPED_UNICODE)])['html'];
    assert_contains('block-text__media--image', $image);
    assert_contains('/uploads/public/about.jpg', $image);
    assert_contains('alt="Рабочая встреча"', $image);

    $video = \App\Core\BlockRenderer::render($base + ['data' => json_encode([
        'variant' => 'intro',
        'content' => '<p>О работе Агентства</p>',
        'media_type' => 'video',
        'media_video' => '/uploads/public/about.mp4',
    ], JSON_UNESCAPED_UNICODE)])['html'];
    assert_contains('<video class="block-text__media-video" controls', $video);
    assert_contains('/uploads/public/about.mp4', $video);

    $youtube = \App\Core\BlockRenderer::render($base + ['data' => json_encode([
        'variant' => 'intro',
        'content' => '<p>О работе Агентства</p>',
        'media_type' => 'youtube',
        'media_youtube' => 'https://youtu.be/dQw4w9WgXcQ',
    ], JSON_UNESCAPED_UNICODE)])['html'];
    assert_contains('youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube);
    assert_contains('loading="lazy"', $youtube);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public-editorial-pages.css');
    assert_contains('.block-text__media--placeholder', $css);
    assert_contains('grid-template-columns: minmax(0, 1.08fr) minmax(300px, .82fr)', $css);
});
