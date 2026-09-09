<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('public chrome avoids first-party executable inline scripts', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    $toolbar = (string) file_get_contents(APP_ROOT . '/app/Core/AppToolbar.php');

    assert_contains("Asset::url('/assets/js/theme-init.js')", $header);
    assert_not_contains('<script nonce=', $header);
    assert_not_contains('<script', $toolbar);
    assert_not_contains('window.__frontendLabels', $footer);
    assert_not_contains('window.__pushEnabled', $footer);
    assert_not_contains('window.__consent', $footer);
    assert_contains('type="application/json" id="frontend-labels"', $footer);
    assert_contains('type="application/json" id="push-config"', $footer);
    assert_contains('type="application/json" id="consent-config"', $footer);
});

test('public landmarks and builder blocks use semantic markup without style attributes', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    $toolbar = (string) file_get_contents(APP_ROOT . '/app/Views/site/components/admin_toolbar.php');
    $hero = (string) file_get_contents(APP_ROOT . '/templates/blocks/hero.php');
    $cards = (string) file_get_contents(APP_ROOT . '/templates/blocks/cards_grid.php');

    // Атрибуты бара администратора вынесены в многострочную запись (см. тест
    // «admin toolbar is maintained as a readable partial component»), поэтому
    // проверяем сам элемент, а не однострочный вариант тега.
    assert_true(
        preg_match('/<aside\s+[^>]*class="app-admin-bar"/', $toolbar) === 1,
        'бар администратора — семантический <aside> с классом app-admin-bar'
    );
    $a11yPanel = (string) file_get_contents(APP_ROOT . '/app/Views/site/_a11y_panel.php');
    assert_contains('<aside class="a11y-drawer"', $a11yPanel);
    assert_contains('<header class="print-only print-header">', $header);
    assert_contains('<footer class="print-only print-footer">', $footer);
    assert_contains('<h3 class="feature-card__title">', $cards);
    assert_contains('<p class="feature-card__text">', $cards);
    assert_not_contains(' style="', $hero);
    assert_not_contains(' style="', $cards);
});

test('all public block templates forbid inline style and script markup', function (): void {
    foreach (glob(APP_ROOT . '/templates/blocks/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        assert_false(
            preg_match('/(?:\s|[\'"])style\s*=/i', $source) === 1,
            basename($file) . ': найден inline style'
        );
        assert_not_contains('<script', strtolower($source), basename($file) . ': найден inline script');
    }
});

test('dynamic block presentation is emitted as scoped CSS, not style attributes', function (): void {
    $cases = [
        'cta' => ['title' => 'CTA', 'bg_color' => '#112233'],
        'cta-band' => ['variant' => 'band', 'title' => 'Band', 'text_color' => '#ffffff'],
        'cta-media' => ['variant' => 'media-dark', 'title' => 'Banner', 'image' => '/uploads/public/banner.jpg'],
        'counters' => ['card_bg' => '#112233', 'items' => [['value' => 1, 'label' => 'Один']]],
        'docs_list' => ['title' => 'Docs', 'columns' => 3, 'items' => [['title' => 'Документ']]],
        'org_structure' => ['head_title' => 'Директор', 'branches' => [['title' => 'Заместитель']]],
        'map_point' => ['title' => 'Карта', 'image' => '/uploads/public/map.jpg'],
        'timeline' => ['title' => 'Этапы', 'cta_title' => 'Подробнее', 'cta_image' => '/uploads/public/timeline.jpg'],
    ];

    $id = 1710;
    foreach ($cases as $case => $data) {
        $type = str_starts_with($case, 'cta-') ? 'cta' : $case;
        $rendered = BlockRenderer::render([
            'id' => $id++,
            'type' => $type,
            'custom_css' => null,
            'data' => json_encode($data),
        ]);
        assert_not_contains(' style=', $rendered['html'], "{$type}: inline style попал в HTML");
        assert_contains('#block-', $rendered['css'], "{$type}: нет scoped CSS");
    }
});

test('organization JSON-LD is emitted once by the site chrome', function (): void {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');

    assert_contains('SeoHelper::organizationSchema($appUrl)', $header);
    assert_not_contains('SchemaOrg::organization(', $footer);
});
