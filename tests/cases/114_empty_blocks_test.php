<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Пустой блок: на сайте не выводится, в предпросмотре — заметка', function () {
    $blocks = [
        ['id' => 801, 'type' => 'slider', 'data' => '{}', 'custom_css' => ''],
        ['id' => 802, 'type' => 'faq', 'data' => '{}', 'custom_css' => ''],
        ['id' => 803, 'type' => 'text', 'data' => json_encode(['title' => 'Заголовок', 'content' => '<p>Текст</p>']), 'custom_css' => ''],
    ];

    // Посетитель: пустых секций с отступами быть не должно.
    $site = BlockRenderer::renderPage($blocks);
    assert_same(1, substr_count($site['html'], '<section'), 'на сайте остаётся только заполненный блок');
    assert_not_contains('cms-empty-notice', $site['html']);

    // Редактор: блок никуда не пропал, видно его тип и приглашение заполнить.
    BlockRenderer::setPreviewMode(true);
    $preview = BlockRenderer::renderPage($blocks);
    BlockRenderer::setPreviewMode(false);
    assert_same(3, substr_count($preview['html'], '<section'));
    assert_contains('Блок «Слайдер» пока пуст', $preview['html']);
    assert_contains('Блок «Вопросы и ответы» пока пуст', $preview['html']);
    assert_contains('/admin/blocks/801/edit', $preview['html'], 'из заметки можно перейти к заполнению');

    // Режим не должен «залипать» между запросами.
    $after = BlockRenderer::renderPage($blocks);
    assert_not_contains('cms-empty-notice', $after['html']);
});

test('Пустота считается по содержимому, а не только по тексту', function () {
    // Галерея из одних фотографий текста не содержит, но пустой не является.
    assert_false(BlockRenderer::isVisuallyEmpty('<div><img src="/uploads/public/a.jpg" alt=""></div>'));
    assert_false(BlockRenderer::isVisuallyEmpty('<div style="background-image:url(\'/a.jpg\')"></div>'));
    assert_false(BlockRenderer::isVisuallyEmpty('<div><svg viewBox="0 0 24 24"></svg></div>'));
    assert_false(BlockRenderer::isVisuallyEmpty('<form><input type="email"></form>'));
    assert_false(BlockRenderer::isVisuallyEmpty('<p>Текст</p>'));

    // А вот пустая обвязка — пустая.
    assert_true(BlockRenderer::isVisuallyEmpty('<div class="wrap"><div class="row"></div></div>'));
    assert_true(BlockRenderer::isVisuallyEmpty('   '));

    // Заготовки состояний с атрибутом hidden браузер не рисует, содержимым они
    // не считаются: иначе пустой блок FAQ «оживал» из-за скрытой подписи
    // «Ничего не найдено» и оставлял на странице секцию с отступами.
    assert_true(BlockRenderer::isVisuallyEmpty('<p class="block-faq__empty" data-faq-empty hidden>Ничего не найдено.</p>'));
    assert_true(BlockRenderer::isVisuallyEmpty('<form hidden><input type="email"></form>'));
    // Но соседний видимый текст пустоту снимает.
    assert_false(BlockRenderer::isVisuallyEmpty('<div><p hidden>скрыто</p><p>видно</p></div>'));
});

test('Все типы блоков имеют русское название для сообщений редактору', function () {
    foreach (array_keys(BlockRenderer::defaults()) as $type) {
        assert_true(
            isset(BlockRenderer::TYPE_LABELS[$type]),
            "нет подписи для типа {$type}: редактор увидит служебный идентификатор"
        );
    }
});
