<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\BlockVisibility;

$mkBlock = static function (array $data, int $id = 1): array {
    return ['id' => $id, 'type' => 'text', 'data' => json_encode($data), 'custom_css' => ''];
};

test('BlockVisibility: окно дат открывается и закрывается', function () {
    $data = ['_visible_from' => '2026-07-10 09:00', '_visible_to' => '2026-07-20 18:00'];

    assert_false(BlockVisibility::isVisible($data, strtotime('2026-07-10 08:59')), 'до начала — скрыт');
    assert_true(BlockVisibility::isVisible($data, strtotime('2026-07-10 09:00')), 'начало включительно');
    assert_true(BlockVisibility::isVisible($data, strtotime('2026-07-15 12:00')), 'внутри окна — виден');
    assert_false(BlockVisibility::isVisible($data, strtotime('2026-07-20 18:00')), 'конец исключительно');
    assert_false(BlockVisibility::isVisible($data, strtotime('2026-07-21 00:00')), 'после конца — скрыт');
});

test('BlockVisibility: пустое расписание = блок виден всегда', function () {
    assert_true(BlockVisibility::isVisible([], strtotime('2026-07-15 12:00')));
    assert_true(BlockVisibility::isVisible(['_visible_from' => '', '_visible_to' => ''], time()));
    assert_same(null, BlockVisibility::boundary([], time()));
    assert_false(BlockVisibility::hasConditions([]));
});

test('BlockVisibility: граница — ближайшая дата в будущем', function () {
    $data = ['_visible_from' => '2026-07-10 09:00', '_visible_to' => '2026-07-20 18:00'];

    // До начала ближайшая граница — старт показа.
    assert_same(strtotime('2026-07-10 09:00'), BlockVisibility::boundary($data, strtotime('2026-07-01 00:00')));
    // Внутри окна — конец показа.
    assert_same(strtotime('2026-07-20 18:00'), BlockVisibility::boundary($data, strtotime('2026-07-15 00:00')));
    // Обе даты в прошлом — пересобирать кэш больше незачем.
    assert_same(null, BlockVisibility::boundary($data, strtotime('2026-08-01 00:00')));
});

test('BlockVisibility: нормализация значения из datetime-local', function () {
    assert_same('2026-07-18 14:30', BlockVisibility::normalize('2026-07-18T14:30'));
    assert_same('2026-07-18T14:30', BlockVisibility::forInput('2026-07-18 14:30'));
    assert_same('', BlockVisibility::normalize(''));
    assert_same('', BlockVisibility::normalize('не дата'));
});

test('BlockRenderer: блок вне окна показа не попадает в HTML', function () use ($mkBlock) {
    $past = $mkBlock(['content' => 'устаревший баннер', '_visible_to' => '2000-01-01 00:00'], 11);
    $future = $mkBlock(['content' => 'ещё не время', '_visible_from' => '2099-01-01 00:00'], 12);
    $live = $mkBlock(['content' => 'актуальный текст'], 13);

    $rendered = BlockRenderer::renderPage([$past, $future, $live]);
    assert_not_contains('устаревший баннер', $rendered['html']);
    assert_not_contains('ещё не время', $rendered['html']);
    assert_contains('актуальный текст', $rendered['html']);
    assert_not_contains('id="block-11"', $rendered['html']);
    assert_contains('id="block-13"', $rendered['html']);
});

test('BlockRenderer: expires_at = ближайшая граница показа на странице', function () use ($mkBlock) {
    $soon = date('Y-m-d H:i', time() + 3600);
    $later = date('Y-m-d H:i', time() + 86400);

    $rendered = BlockRenderer::renderPage([
        $mkBlock(['content' => 'a', '_visible_to' => $later], 21),
        $mkBlock(['content' => 'b', '_visible_to' => $soon], 22),
    ]);
    assert_same(strtotime($soon), $rendered['expires_at'], 'кэш обязан истечь к ближайшей дате');

    // Блок, который ещё не начался, тоже задаёт границу — иначе кэш заморозит
    // страницу и блок не появится в назначенное время.
    $pending = BlockRenderer::renderPage([$mkBlock(['content' => 'c', '_visible_from' => $soon], 23)]);
    assert_same(strtotime($soon), $pending['expires_at']);

    // Страница без расписания кэшируется бессрочно, как и раньше.
    $plain = BlockRenderer::renderPage([$mkBlock(['content' => 'd'], 24)]);
    assert_same(null, $plain['expires_at']);
});

test('BlockRenderer: ограничение по устройству — класс, а не вырезание из HTML', function () use ($mkBlock) {
    $mobile = BlockRenderer::render($mkBlock(['content' => 'моб', '_visible_device' => 'mobile'], 31));
    assert_contains('cms-block--only-mobile', $mobile['html']);
    assert_contains('моб', $mobile['html'], 'контент остаётся в HTML — скрытие делает CSS');

    $desktop = BlockRenderer::render($mkBlock(['content' => 'десктоп', '_visible_device' => 'desktop'], 32));
    assert_contains('cms-block--only-desktop', $desktop['html']);

    $any = BlockRenderer::render($mkBlock(['content' => 'все'], 33));
    assert_not_contains('cms-block--only-', $any['html']);

    // Мусор в поле не превращается в класс.
    $junk = BlockRenderer::render($mkBlock(['content' => 'x', '_visible_device' => 'tv"><script>'], 34));
    assert_not_contains('cms-block--only-', $junk['html']);
    assert_not_contains('<script>', $junk['html']);
});

test('BlockVisibility: подпись условий для списка блоков в админке', function () {
    $label = BlockVisibility::label(
        ['_visible_from' => '2026-07-10 09:00', '_visible_to' => '2026-07-20 18:00'],
        strtotime('2026-07-15 12:00')
    );
    assert_contains('10.07.2026 09:00', $label);
    assert_contains('20.07.2026 18:00', $label);

    $done = BlockVisibility::label(['_visible_to' => '2026-07-20 18:00'], strtotime('2026-07-21 00:00'));
    assert_contains('показ завершён', $done);

    $pending = BlockVisibility::label(['_visible_from' => '2026-07-20 18:00'], strtotime('2026-07-01 00:00'));
    assert_contains('ещё не показывается', $pending);

    assert_contains('только мобильные', BlockVisibility::label(['_visible_device' => 'mobile']));
    assert_same('', BlockVisibility::label([]));
});
