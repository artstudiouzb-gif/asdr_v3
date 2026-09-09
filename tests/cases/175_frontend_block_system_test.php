<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\Lang;
use App\Core\PagePresets;

test('Анимация блока: выключенный режим не переопределяется фронтендом', function (): void {
    $rendered = BlockRenderer::render([
        'id' => 17501,
        'type' => 'text',
        'data' => json_encode([
            'content' => '<p>Видимый текст</p>',
            '_reveal' => ['enabled' => false, 'type' => 'fade'],
        ], JSON_UNESCAPED_UNICODE),
    ]);

    assert_not_contains("\n    data-reveal", $rendered['html']);

    $frontend = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    assert_not_contains("querySelectorAll('.cms-block:not(.cms-block--hero)')", $frontend);
});

test('Анимация блока: stagger помечает элементы, а не скрывает всю секцию', function (): void {
    $rendered = BlockRenderer::render([
        'id' => 17502,
        'type' => 'cards_grid',
        'data' => json_encode([
            'items' => [
                ['title' => 'A', 'text' => '', 'url' => ''],
                ['title' => 'B', 'text' => '', 'url' => ''],
            ],
            '_reveal' => ['enabled' => true, 'type' => 'stagger'],
        ], JSON_UNESCAPED_UNICODE),
    ]);

    assert_contains('data-reveal-items', $rendered['html']);
    assert_not_contains('data-reveal-type="stagger"', $rendered['html']);
});

test('Галерея использует единый lightbox из общего frontend bundle', function (): void {
    $collector = (string) file_get_contents(APP_ROOT . '/app/Core/AssetCollector.php');
    $frontend = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');

    assert_not_contains('/assets/js/blocks/gallery.js', $collector);
    // Список контейнеров явный: фотографию открывает лайтбокс только там, где
    // это задумано, а не у любой ссылки на файл. Блок «Изображение» попадает в
    // список отдельным классом — его ставит шаблон при включённом увеличении.
    assert_contains(
        "var PHOTO_SCOPES = '.album-photos, .newsdetail-photos__grid, .mediagallery-grid, .block-image--zoomable'",
        $frontend
    );
    assert_true(str_ends_with(trim($frontend), '})();'), 'общий IIFE должен закрываться в конце bundle');
    assert_same(1, substr_count($frontend, "\n})();"), 'frontend helpers не должны выпадать из общего scope');
});

test('Точки хронологии и этапов не обрезаются карточным spotlight-эффектом', function (): void {
    $frontend = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');

    assert_contains('.stage, .timeline-item {', $frontend);
    assert_contains('overflow: visible;', $frontend);
    assert_not_contains('.faq-item, .stage, .timeline-item,', $frontend);
    assert_not_contains('.faq-item::before, .stage::before, .timeline-item::before,', $frontend);
    assert_not_contains('.faq-item:hover::before, .stage:hover::before, .timeline-item:hover::before,', $frontend);
});

test('Цветовая цепочка этапов различает завершённый, текущий и запланированный статусы', function (): void {
    $theme = theme_css();

    // Соединительная линия завершённого этапа красится по состоянию следующего
    // этапа (`stage--next-done` / `stage--next-active`), а не по
    // `:not(:last-child)`. Точные правила проверяет 178_stage_timeline_status.
    assert_contains('.stage--done.stage--next-done::before', $theme);
    assert_contains('.stage--done.stage--next-active::before', $theme);
    assert_contains('.stage--done .stage__dot { background: var(--gov-teal);', $theme);
    assert_contains('.stage--active .stage__dot {', $theme);
    // Текущий этап красится акцентом из настроек, а не фиксированным синим:
    // раньше здесь стоял #2f80ed — тот самый хардкод бренд-цвета, который
    // правила проекта запрещают.
    assert_contains('border-color: var(--gov-teal);', $theme);
    assert_not_contains('#2f80ed', $theme, 'чужой синий не должен возвращаться в тему');
    assert_contains('.stage--planned .stage__dot {', $theme);
    assert_contains('.stage--planned .stage__status {', $theme);
});

test('Системные подписи блоков переведены на узбекский', function (): void {
    assert_same('Hozircha yangiliklar yo‘q.', Lang::t('Новостей пока нет.', 'uz'));
    assert_same('Media filtri', Lang::t('Фильтр медиа', 'uz'));
    assert_same('Rejalashtirilgan', Lang::t('Запланирован', 'uz'));
    assert_same('Keyingi slayd', Lang::t('Следующий слайд', 'uz'));
});

test('UZ-сборка главной использует узбекский demo fixture', function (): void {
    $fixture = json_decode(
        (string) file_get_contents(APP_ROOT . '/database/demo_assets/home_blocks_uz.json'),
        true
    );
    $preset = PagePresets::find('home', 'uz');

    assert_true(is_array($fixture) && $fixture !== []);
    assert_true(is_array($preset));
    assert_same(
        (string) ($fixture[0]['data']['title'] ?? ''),
        (string) ($preset['blocks'][0]['data']['title'] ?? '')
    );
});
