<?php

declare(strict_types=1);

use App\Core\AssetCollector;

/**
 * Части темы, вынесенные из общего бандла: файл -> регулярка семейства.
 * Всё, что лежит в файле, обязано принадлежать этому семейству.
 */
const THEME_PART_FAMILIES = [
    'news-detail.css' => '/newsdetail|relnews/',
    'org-structure.css' => '/orgstruct/',
    // Обложка. `.hero` не пересекается с `.block-hero` старого блока: там
    // точка стоит перед «block», а не перед «hero». Шаги @keyframes своего
    // семейства не имеют — разрешены явно и только они.
    'hero.css' => '/\.hero|^(from|to|[\d.]+%)$/',
];

/** Разбор CSS на правила верхнего уровня: [селектор без комментариев, тело]. */
function split_css_rules(string $css): array
{
    $out = [];
    $i = 0;
    $n = strlen($css);
    while ($i < $n) {
        $b = strpos($css, '{', $i);
        if ($b === false) {
            break;
        }
        $selector = substr($css, $i, $b - $i);
        $depth = 1;
        $j = $b + 1;
        while ($j < $n && $depth > 0) {
            if ($css[$j] === '{') {
                $depth++;
            } elseif ($css[$j] === '}') {
                $depth--;
            }
            $j++;
        }
        $bare = trim((string) preg_replace('#/\*.*?\*/#s', '', $selector));
        $out[] = [$bare, substr($css, $b + 1, $j - $b - 2)];
        $i = $j;
    }

    return $out;
}

test('В вынесенных частях темы нет чужих правил', function (): void {
    foreach (THEME_PART_FAMILIES as $file => $family) {
        $path = APP_ROOT . '/public/assets/css/blocks/' . $file;
        assert_true(is_file($path), $file . ' существует');
        $css = (string) file_get_contents($path);

        $foreign = [];
        $check = static function (string $selector) use ($family, &$foreign): void {
            if ($selector === '') {
                return;
            }
            // Проверяем КАЖДУЮ часть группового селектора. Строка вида
            // «.newsdetail-photos__item:hover, .album-card:hover, .btn:hover»
            // содержит искомое, но уносить её нельзя: на остальных страницах
            // карточки и кнопки молча потеряли бы своё правило. Именно так
            // при первом выносе утёк .block-hero__subtitle.
            foreach (array_filter(array_map('trim', explode(',', $selector))) as $part) {
                if (preg_match($family, $part) !== 1) {
                    $foreign[] = $part;
                }
            }
        };

        foreach (split_css_rules($css) as [$selector, $body]) {
            if (str_starts_with($selector, '@')) {
                foreach (split_css_rules($body) as [$inner]) {
                    $check($inner);
                }
                continue;
            }
            $check($selector);
        }

        assert_same(
            [],
            array_slice(array_unique($foreign), 0, 8),
            $file . ': правила не из своего семейства уносить нельзя'
        );
    }
});

test('Часть темы подключается отдельно и только по запросу', function (): void {
    AssetCollector::reset();
    assert_same('', AssetCollector::renderThemeStyles(), 'без запроса часть темы не подключается');

    AssetCollector::requireThemePart('news_detail');
    assert_true(
        preg_match('#/assets/css/blocks/news-detail(\.min)?\.css#', AssetCollector::renderThemeStyles()) === 1,
        'подключён файл части темы'
    );

    // Повторный запрос не должен дублировать <link>.
    AssetCollector::requireThemePart('news_detail');
    assert_same(1, substr_count(AssetCollector::renderThemeStyles(), '<link'));
    AssetCollector::reset();
});

test('Часть темы, названная типом блока, подключается сама', function (): void {
    AssetCollector::reset();
    // PageController прогоняет через requireJs каждый тип блока страницы
    // (BlockRenderer::renderPage() отдаёт их списком), поэтому строки в
    // THEME_PART_MAP достаточно — правок в контроллерах не нужно.
    AssetCollector::requireJs('org_structure');
    assert_true(
        preg_match('#/assets/css/blocks/org-structure(\.min)?\.css#', AssetCollector::renderThemeStyles()) === 1,
        'стили блока подключились по типу блока'
    );
    AssetCollector::reset();
});

test('Часть темы выводится после бандла, но до дизайн-CSS из админки', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');

    // Берём именно места вывода <link>, а не первое упоминание переменной:
    // $generatedCssUrls вычисляется заметно выше своего цикла вывода.
    $bundle = strpos($header, 'FrontendAssets::styles()');
    $part = strpos($header, 'AssetCollector::renderThemeStyles()');
    $generated = strpos($header, 'data-generated-site-css');
    $blocks = strpos($header, 'AssetCollector::renderStyles()');

    assert_true($bundle !== false && $part !== false && $generated !== false && $blocks !== false);
    // Порядок обязателен: вынесенные правила должны стоять там же, где лежали
    // внутри темы. Уедут после дизайн-CSS — настройки раздела «Дизайн»
    // перестанут действовать на этих страницах.
    assert_true($bundle < $part, 'часть темы идёт после общего бандла');
    assert_true($part < $generated, 'часть темы идёт до дизайн-CSS админки');
    assert_true($generated < $blocks, 'стили блоков остаются последними');
});

test('Новостные страницы запрашивают свои части темы', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/NewsController.php');

    // Детальная новость по-прежнему использует news_detail напрямую.
    assert_same(
        1,
        substr_count($controller, "requireThemePart('news_detail')"),
        'детальная новость запрашивает news_detail напрямую'
    );

    // Лента теперь использует общий news_feature interaction, как на главной.
    // AssetCollector должен через него подключить и relnews-базу, и block CSS.
    assert_same(
        1,
        substr_count($controller, "requireJs('news_feature')"),
        'список новостей запрашивает news_feature'
    );

    AssetCollector::reset();
    AssetCollector::requireJs('news_feature');
    assert_true(
        preg_match('#/assets/css/blocks/news-detail(\.min)?\.css#', AssetCollector::renderThemeStyles()) === 1,
        'news_feature подключает базовые стили relnews-card'
    );
    assert_true(
        preg_match('#/assets/css/blocks/news-feature(\.min)?\.css#', AssetCollector::renderStyles()) === 1,
        'news_feature подключает общий feature-card слой'
    );
    AssetCollector::reset();
});

test('Классы, живущие вне своего раздела, остались в общей теме', function (): void {
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    // Виджет подписки может стоять в сайдбаре любой страницы, а
    // .newsdetail-article__content и .newsdetail__badge есть в project_show.php.
    // Уедут в часть темы — пропадут вне новостей.
    foreach (['newsdetail-subscribe', 'newsdetail-article__content', 'newsdetail__badge'] as $needle) {
        assert_contains($needle, $theme, $needle . ' обязан остаться в общей теме');
    }
});

test('Сброс hover едет вместе со своим правилом', function (): void {
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    $part = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');

    // Часть темы подключается позже gov-theme, поэтому сброс transform для
    // тач-устройств обязан лежать в том же файле, что и правило hover, —
    // иначе при тапе плитка «залипает» приподнятой.
    assert_not_contains('.newsdetail-photos__item:hover, .album-card:hover', $theme);
    assert_contains('@media (hover: none)', $part);
});