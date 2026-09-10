<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer;

test('Block presentation normalizer: формирует прежние значения по умолчанию', function (): void {
    assert_same([
        '_anchor' => '',
        '_spacing' => 'premium',
        '_reveal' => ['enabled' => false, 'type' => 'fade'],
        '_bg' => 'none',
        '_surface' => 'flat',
        '_fullwidth' => false,
        '_pad_top' => 'default',
        '_pad_bottom' => 'default',
        '_visible_from' => '',
        '_visible_to' => '',
        '_visible_device' => '',
        // Своя заливка секции не задана — режим «пресет темы».
        '_bg_mode' => 'preset',
        // Цвет текста секции и цвет вложенных карточек — два независимых
        // уровня, и оба сохраняются при любом режиме фона (в том числе у
        // пресета navy, где своей заливки нет).
        '_bg_text_scheme' => 'auto',
        '_bg_text_color' => '',
        '_bg_card_scheme' => 'auto',
        '_bg_light_text' => false,
    ], BlockPresentationNormalizer::normalize([]));
});

test('Block presentation normalizer: сохраняет допустимые настройки', function (): void {
    assert_same([
        '_anchor' => 'forma',
        '_spacing' => 'max',
        '_reveal' => ['enabled' => true, 'type' => 'slide-up'],
        '_bg' => 'navy',
        '_surface' => 'card',
        '_fullwidth' => true,
        '_pad_top' => 'none',
        '_pad_bottom' => 'large',
        '_visible_from' => '2026-07-24 10:15',
        '_visible_to' => '2026-07-25 18:30',
        '_visible_device' => 'mobile',
        '_bg_mode' => 'preset',
        '_bg_text_scheme' => 'light',
        '_bg_text_color' => '',
        '_bg_card_scheme' => 'auto',
        '_bg_light_text' => true,
    ], BlockPresentationNormalizer::normalize([
        'anchor' => '#Forma',
        'spacing' => 'max',
        // Пресет navy с собственным цветом текста: до исправления настройка
        // до него не доходила — выход по «preset» стоял раньше.
        'bg_text_scheme' => 'light',
        'reveal_type' => 'slide-up',
        'bg' => 'navy',
        'surface' => 'card',
        'fullwidth' => '1',
        'pad_top' => 'none',
        'pad_bottom' => 'large',
        'visible_from' => '2026-07-24T10:15',
        'visible_to' => '2026-07-25T18:30',
        'visible_device' => 'mobile',
        'unrelated' => 'не попадает в результат',
    ]));
});

test('Block presentation normalizer: безопасно нормализует якорь секции', function (): void {
    assert_same('forma-zayavki', BlockPresentationNormalizer::normalize([
        'anchor' => '  #Forma zayavki  ',
    ])['_anchor']);
    assert_same('', BlockPresentationNormalizer::normalize(['anchor' => '#block-123'])['_anchor']);
    assert_same('', BlockPresentationNormalizer::normalize(['anchor' => '###'])['_anchor']);
});

test('Block presentation normalizer: ограничивает неизвестные значения', function (): void {
    $data = BlockPresentationNormalizer::normalize([
        'spacing' => 'huge',
        'reveal_type' => 'spin',
        'bg' => 'script',
        'surface' => 'floating',
        'fullwidth' => '0',
        'pad_top' => 'giant',
        'pad_bottom' => [],
        'visible_from' => 'not-a-date',
        'visible_to' => '<script>',
        'visible_device' => 'tablet',
    ]);

    assert_same('premium', $data['_spacing']);
    assert_same(['enabled' => false, 'type' => 'fade'], $data['_reveal']);
    assert_same('none', $data['_bg']);
    assert_same('flat', $data['_surface']);
    assert_false($data['_fullwidth']);
    assert_same('default', $data['_pad_top']);
    assert_same('default', $data['_pad_bottom']);
    assert_same('', $data['_visible_from']);
    assert_same('', $data['_visible_to']);
    assert_same('', $data['_visible_device']);
});

test('Block presentation normalizer: поддерживает каскад карточек', function (): void {
    $data = BlockPresentationNormalizer::normalize(['reveal_type' => 'stagger']);

    assert_same(['enabled' => true, 'type' => 'stagger'], $data['_reveal']);
});

test('Block renderer: добавляет карточный контейнер только по настройке секции', function (): void {
    $card = \App\Core\BlockRenderer::render([
        'id' => 9137,
        'type' => 'person_profile',
        'custom_css' => '',
        'data' => json_encode(['name' => 'Директор', '_surface' => 'card']),
    ]);
    $flat = \App\Core\BlockRenderer::render([
        'id' => 9138,
        'type' => 'person_profile',
        'custom_css' => '',
        'data' => json_encode(['name' => 'Директор', '_surface' => 'flat']),
    ]);

    assert_contains('cms-block--surface-card', (string) ($card['html'] ?? ''));
    assert_not_contains('cms-block--surface-card', (string) ($flat['html'] ?? ''));
});

test('Block renderer: отмечает только явно заданные независимые отступы', function (): void {
    $custom = \App\Core\BlockRenderer::render([
        'id' => 9141,
        'type' => 'text',
        'custom_css' => '',
        'data' => json_encode([
            'content' => '<p>Текст</p>',
            '_pad_top' => 'none',
            '_pad_bottom' => 'large',
        ]),
    ]);
    $defaults = \App\Core\BlockRenderer::render([
        'id' => 9142,
        'type' => 'text',
        'custom_css' => '',
        'data' => json_encode(['content' => '<p>Текст</p>']),
    ]);

    $customHtml = (string) ($custom['html'] ?? '');
    $customCss = (string) ($custom['css'] ?? '');
    $defaultHtml = (string) ($defaults['html'] ?? '');

    assert_contains('cms-block--pad-top-custom', $customHtml);
    assert_contains('cms-block--pad-bottom-custom', $customHtml);
    assert_contains('--block-pad-top:0;', $customCss);
    assert_contains('--block-pad-bottom:var(--space-max);', $customCss);
    assert_not_contains('cms-block--pad-top-custom', $defaultHtml);
    assert_not_contains('cms-block--pad-bottom-custom', $defaultHtml);
});

test('Block presentation normalizer: обнаруживает перевёрнутое окно показа', function (): void {
    assert_true(BlockPresentationNormalizer::hasInvalidVisibilityWindow([
        '_visible_from' => '2026-07-25 10:00',
        '_visible_to' => '2026-07-24 10:00',
    ]));
    assert_true(BlockPresentationNormalizer::hasInvalidVisibilityWindow([
        '_visible_from' => '2026-07-25 10:00',
        '_visible_to' => '2026-07-25 10:00',
    ]));
    assert_false(BlockPresentationNormalizer::hasInvalidVisibilityWindow([
        '_visible_from' => '2026-07-24 10:00',
        '_visible_to' => '2026-07-25 10:00',
    ]));
    assert_false(BlockPresentationNormalizer::hasInvalidVisibilityWindow([]));
});

test('Контроллер делегирует общие настройки BlockPresentationNormalizer', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');

    assert_contains('array_merge($data, BlockPresentationNormalizer::normalize($_POST))', $controller);
    assert_contains('BlockPresentationNormalizer::hasInvalidVisibilityWindow($data)', $controller);
    assert_not_contains('$allowedReveal =', $controller);
    assert_not_contains('$padOptions =', $controller);

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    assert_contains('name="surface"', $form);
    assert_contains('Карточка с фоном', $form);
});
