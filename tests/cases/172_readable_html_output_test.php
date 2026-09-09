<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('block wrapper renders one public attribute per line', function (): void {
    $rendered = BlockRenderer::render([
        'id' => 72,
        'type' => 'text',
        'data' => json_encode([
            'content' => '<p>Readable output</p>',
            '_reveal' => ['enabled' => true, 'type' => 'slide-up'],
        ]),
        'custom_css' => null,
    ]);

    assert_contains("<section\n    id=\"block-72\"\n    class=\"cms-block", $rendered['html']);
    assert_contains("\n    data-block-type=\"text\"\n    data-reveal\n    data-reveal-type=\"slide-up\"\n>", $rendered['html']);
    assert_contains("\n</section>", $rendered['html']);
});

test('admin toolbar is maintained as a readable partial component', function (): void {
    $core = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/app/Core/AppToolbar.php'));
    $partial = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/app/Views/site/components/admin_toolbar.php'));

    assert_contains("View::renderPartial('site/components/admin_toolbar'", $core);
    assert_not_contains('<<<HTML', $core);
    assert_contains("<aside\n    class=\"app-admin-bar\"", $partial);
    // Иконки берутся из общего реестра Tabler, а не пишутся <svg> руками — того
    // же требует тест «общая навигация не содержит локальных SVG-реестров».
    // Прежняя проверка на литеральный «<svg» противоречила ему.
    assert_contains('Icon::render(', $partial);
    assert_not_contains('<path', $partial);
});

test('cards template does not generate element names or links through string concatenation', function (): void {
    $template = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/templates/blocks/cards_grid.php'));

    assert_not_contains('$tag =', $template);
    assert_contains('<a class="feature-card"', $template);
    // Хвост тега открыт: у карточки бывает условный атрибут (`data-carousel-item`
    // в режиме слайдера). Стережём имя элемента в разметке, а не его атрибуты.
    assert_contains('<article class="feature-card"', $template);
    assert_contains('<h3 class="feature-card__title">', $template);
});

test('document and site header attributes are maintained in multiline markup', function (): void {
    $header = str_replace("\r\n", "\n", (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php'));

    assert_contains("<html\n    lang=", $header);
    assert_contains("<header\n    class=", $header);
    assert_contains('$headerClasses = [', $header);
    assert_not_contains('site-header--layout-', $header);
});
