<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer;
use App\Core\SectionColors;
use App\Core\SectionHead;

/**
 * Цвет секции и цвет вложенных в неё карточек — два разных уровня.
 *
 * Раньше «Светлый текст» и пресет navy красили потомков напрямую
 * (`.cms-block--bg-navy h3 { color:#fff }`), и правило добивало до заголовка
 * внутри белой карточки: белый текст на белом фоне. Теперь цвет секции — это
 * переменные, а компонент со своей поверхностью объявляет их себе заново.
 */

test('Цвет секции: «авто» ничего не печатает, остальные схемы задают переменные', function () {
    assert_same('', SectionColors::build(['_bg_text_scheme' => 'auto'], 5), 'без настройки CSS не появляется');

    $light = SectionColors::build(['_bg_text_scheme' => 'light'], 5);
    assert_contains('#block-5{--section-fg:rgba(255,255,255,.92)', $light);
    assert_contains('--section-title-fg:#fff', $light);
    // Цвет уходит переменной, а элементы ссылаются на неё: карточка сможет
    // объявить свою, и спорить о специфичности будет не с чем.
    assert_contains('{color:var(--section-title-fg)}', $light);
    assert_contains('{color:var(--section-muted-fg)}', $light);
    assert_contains('{color:var(--section-link-fg)}', $light);

    $dark = SectionColors::build(['_bg_text_scheme' => 'dark'], 5);
    assert_contains('--section-title-fg:var(--gov-title)', $dark);
});

test('Цвет секции: «светлый текст» распространяется на весь текст, а не на заголовок', function () {
    $css = SectionColors::build(['_bg_text_scheme' => 'light'], 5);

    foreach (['h1', 'h2', 'h3', '[class$="__title"]'] as $needle) {
        assert_contains($needle, $css, 'заголовки: ' . $needle);
    }
    foreach (['p', 'li', 'blockquote', 'figcaption', '.rich-content', '[class*="__desc"]', '[class*="__lead"]'] as $needle) {
        assert_contains($needle, $css, 'тело текста: ' . $needle);
    }
    assert_contains('.section-head__all', $css, 'ссылка «все» тоже светлеет');
});

test('Цвет секции: старый флажок «Светлый текст» читается как схема light', function () {
    // Страницы, сохранённые до появления настройки, светлый текст не теряют.
    assert_same('light', SectionColors::textScheme(['_bg_light_text' => true]));
    assert_same('auto', SectionColors::textScheme([]));
    // Новый ключ главнее старого флажка.
    assert_same('dark', SectionColors::textScheme(['_bg_text_scheme' => 'dark', '_bg_light_text' => true]));
    assert_same('auto', SectionColors::textScheme(['_bg_text_scheme' => 'нет-такой-схемы']));
});

test('Цвет секции: свой цвет принимается только как #rrggbb', function () {
    $ok = SectionColors::build(['_bg_text_scheme' => 'custom', '_bg_text_color' => '#FFE08A'], 5);
    assert_contains('--section-fg:#ffe08a', $ok);
    // Подпись приглушается тем же цветом: одинаковый тон у заголовка и
    // описания стирает разницу между ними.
    assert_contains('color-mix(in srgb, #ffe08a 82%', $ok);

    assert_same('', SectionColors::build(['_bg_text_scheme' => 'custom', '_bg_text_color' => 'red; }'], 5),
        'мусор в поле цвета не печатает правил вовсе');
});

test('Карточки: «своя схема» — умолчание и не печатает правил', function () {
    assert_same('auto', SectionColors::cardScheme([]));
    assert_same('auto', SectionColors::cardScheme(['_bg_card_scheme' => 'что-то']));

    // Ничего не печатаем намеренно: карточка объявляет переменные себе сама
    // (правило в gov-theme.css), и это ровно то поведение, которое нужно.
    $css = SectionColors::build(['_bg_card_scheme' => 'auto'], 5);
    assert_not_contains('.feature-card', $css);
});

test('Карточки: светлая и тёмная схемы перекрывают поверхность явно', function () {
    $lightCards = SectionColors::build(['_bg_card_scheme' => 'light'], 5);
    assert_contains('#block-5 :is(.act-card,', $lightCards);
    assert_contains('background:#fff', $lightCards);
    assert_contains('--section-title-fg:var(--gov-title)', $lightCards);

    $darkCards = SectionColors::build(['_bg_text_scheme' => 'light', '_bg_card_scheme' => 'dark'], 5);
    assert_contains('background:rgba(255,255,255,.08)', $darkCards);
    assert_contains('--section-title-fg:#fff', $darkCards);
});

test('Карточки: на тёмной секции стеклянная поверхность становится непрозрачной', function () {
    // Карточки темы полупрозрачны. Сквозь них просвечивает тёмная секция,
    // поверхность уходит в серо-синий, и тёмный текст карточки теряет
    // контраст — на тёмной секции заливку делаем сплошной.
    $css = SectionColors::build(['_bg_text_scheme' => 'light'], 5);
    assert_contains('background-color:var(--gov-surface)', $css);

    // Если редактор сам выбрал схему карточек, не вмешиваемся.
    $chosen = SectionColors::build(['_bg_text_scheme' => 'light', '_bg_card_scheme' => 'dark'], 5);
    assert_not_contains('background-color:var(--gov-surface)', $chosen);
});

test('Список поверхностей PHP и CSS не расходится', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    // В теме список объявлен дважды (светлая и тёмная тема) плюс отдельным
    // правилом для navy-секции: расхождение любого из них вернёт белый текст
    // на белой карточке.
    foreach (SectionColors::SURFACES as $selector) {
        assert_true(
            substr_count($css, $selector . ',') + substr_count($css, $selector . ')') >= 3,
            'селектор ' . $selector . ' перечислен во всех правилах поверхностей'
        );
    }

    assert_contains('--section-fg: var(--text-primary)', $css, 'поверхность возвращает себе обычный цвет');
    assert_contains('--section-title-fg: var(--gov-title)', $css);
});

test('Пресет Navy: заголовки красятся переменной, а не прямым цветом', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    // Прямое правило добивало до заголовка внутри белой карточки — именно так
    // на navy-секции появлялся белый текст на белом фоне.
    assert_not_contains('.cms-block--bg-navy h1, .cms-block--bg-navy h2', $css);
    assert_contains('.cms-block--bg-navy :is(h1, h2, h3, .section-head__title) { color: var(--section-title-fg); }', $css);
    assert_contains('.cms-block--bg-navy .section-head__all { color: var(--section-link-fg); }', $css);
    // На тёмной секции карточка перестаёт быть стеклянной — иначе сквозь неё
    // просвечивает navy и тёмный текст карточки теряет контраст.
    assert_true(
        preg_match('/\.cms-block--bg-navy :is\([^)]*\.feature-card[^)]*\) \{ background-color: var\(--gov-surface\); \}/', $css) === 1,
        'на navy-секции поверхность карточки непрозрачна'
    );
});

test('Нормализатор: цвет секции и цвет карточек сохраняются отдельно', function () {
    $data = BlockPresentationNormalizer::normalize([
        'bg_text_scheme' => 'light',
        'bg_card_scheme' => 'dark',
        'bg_text_color' => '#112233',
    ]);

    assert_same('light', $data['_bg_text_scheme']);
    assert_same('dark', $data['_bg_card_scheme']);
    assert_same('#112233', $data['_bg_text_color']);
    // Прежний флажок держим в синхроне ради уже сохранённых страниц.
    assert_true($data['_bg_light_text'], 'старый ключ повторяет новую схему');

    $auto = BlockPresentationNormalizer::normalize([]);
    assert_same('auto', $auto['_bg_text_scheme']);
    assert_same('auto', $auto['_bg_card_scheme']);
    assert_false($auto['_bg_light_text']);
});

test('Шапка секции: ссылка «все» умеет стоять после инструментов', function () {
    $options = [
        'title' => 'Медиатека',
        'all_text' => 'Все материалы',
        'all_url' => '/media',
        'tools' => '<div class="media-tabs">вкладки</div>',
    ];

    // По умолчанию ссылка идёт первой — так у каруселей, где следом стрелки.
    $default = SectionHead::render($options);
    assert_true(
        strpos($default, 'section-head__all') < strpos($default, 'media-tabs'),
        'порядок по умолчанию не изменился'
    );

    $swapped = SectionHead::render($options + ['all_after_tools' => true]);
    assert_true(
        strpos($swapped, 'media-tabs') < strpos($swapped, 'section-head__all'),
        'вкладки идут первыми, ссылка — после них'
    );
    assert_contains('Все материалы →', $swapped);
});

test('Медиатека: ссылка «все» стоит после вкладок и прижата вправо', function () {
    $template = (string) file_get_contents(APP_ROOT . '/templates/blocks/media_gallery.php');
    assert_contains("'all_after_tools' => true", $template);
    assert_contains('section-head--split-tools', $template);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/media-gallery.css');
    // Сетка шапки — две колонки: третья осталась от разметки, где вкладки были
    // прямым потомком, и с тех пор пустовала.
    assert_contains('grid-template-columns: minmax(0, 1fr) auto;', $css);
    assert_not_contains('minmax(0, 1fr) auto minmax(0, 1fr)', $css);
    assert_contains('.block-mediagallery__head .section-head__all { margin-inline-start: auto; }', $css,
        'на узком экране автоотступ прижимает ссылку к правому краю строки вкладок');
});

test('Демо-контент: обложка главной — отдельная запись, обе языковые версии ссылаются на неё (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();

    \App\Core\DemoSeeder::resetAndRun($pdo);

    $heroes = $pdo->query('SELECT * FROM heroes WHERE deleted_at IS NULL')->fetchAll();
    assert_same(1, count($heroes), 'демо-комплект создаёт одну обложку');
    $heroId = (int) $heroes[0]['id'];
    assert_same('published', (string) $heroes[0]['status']);

    $slides = \App\Models\HeroSlide::forHero($heroId);
    assert_true(count($slides) >= 3, 'у обложки несколько слайдов');

    // Обе языковые версии главной ссылаются на ОДНУ обложку: текст слайда
    // переводится, а медиа и раскладка у него общие.
    $blocks = $pdo->query(
        "SELECT b.data, p.lang FROM blocks b
         INNER JOIN pages p ON p.id = b.page_id
         WHERE b.type = 'hero' AND (p.is_home = 1 OR p.slug = 'home')"
    )->fetchAll();
    assert_true(count($blocks) >= 2, 'обложка стоит на обеих языковых версиях главной');
    foreach ($blocks as $block) {
        $data = json_decode((string) $block['data'], true);
        assert_same($heroId, (int) ($data['hero_id'] ?? 0), 'блок на ' . $block['lang'] . ' ссылается на обложку');
    }

    $uz = \App\Models\HeroSlide::forDisplay($heroId, 'uz');
    assert_true($uz !== [], 'узбекская версия отдаёт слайды');
    assert_not_contains('стратегической', (string) $uz[0]['data']['title'], 'заголовок переведён');

    // Повторный запуск не плодит обложки.
    \App\Core\DemoSeeder::run($pdo);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM heroes WHERE deleted_at IS NULL')->fetchColumn());
});

test('Акцент на светлой карточке берётся из «Дизайна», а не из фиксированного цвета', function () {
    // Тёмная секция переопределяет --gov-teal-text на светлый вариант, и белая
    // карточка внутри неё его наследовала — на белом он нечитаем. Чинить это
    // фиксированным цветом нельзя: акцент задаётся в админке. Поэтому есть
    // отдельное имя, которое секции не трогают.
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    assert_contains('--gov-teal-text: var(--gov-teal-on-light);', $theme,
        'карточка берёт акцент по имени, а не фиксированным цветом');
    assert_contains('--gov-teal-on-light: #127778;', $theme,
        'у имени есть статический откат для установки без сгенерированной темы');

    $generator = (string) file_get_contents(APP_ROOT . '/app/Core/SiteThemeCss.php');
    assert_contains("'--gov-teal-on-light' => AccentContrast::onLight(", $generator,
        'настоящее значение считается по контрасту из настроек «Дизайна»');
});

test('Кнопка обложки: цвет текста на заливке акцентом считает тема', function () {
    // Белый читается не на всяком акценте — axe ловил именно эту пару.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    assert_contains('.hero__cta--primary { background: var(--hero-accent); color: var(--on-accent, #fff); }', $css);
    assert_true(
        preg_match('/\.hero__cta--primary\s*\{[^}]*color:\s*#fff\s*[;}]/', $css) !== 1,
        'фиксированного белого у основной кнопки не осталось'
    );
});
