<?php

declare(strict_types=1);

/**
 * Тест корректного открытия активного слайда галереи новости в лайтбоксе:
 * 1. В news-detail.css неактивные абсолютные слайды получают pointer-events: none и z-index: 0,
 *    а активный слайд (.is-active) — pointer-events: auto и z-index: 1.
 * 2. .newsdetail-gallery__main имеет явный курсор zoom-in.
 * 3. В frontend.js галерея сохраняет root.__ndgShow = show для синхронизации.
 * 4. Обработчик клика лайтбокса при клике на фото внутри [data-ndgallery] всегда находит
 *    текущий активный слайд (.newsdetail-gallery__slide.is-active).
 * 5. Контейнер .newsdetail-gallery__main имеет прямой клик-хэндлер, открывающий активный слайд.
 * 6. Навигация внутри лайтбокса синхронизирует слайдер на странице через gallery.__ndgShow(galleryIndex).
 */

test('Стили галереи: неактивные слайды не перехватывают клики у активного слайда', function () {
    $root = dirname(__DIR__, 2);
    $css = (string) file_get_contents($root . '/public/assets/css/blocks/news-detail.css');

    assert_contains('.newsdetail-gallery__slide {', $css);
    assert_contains('pointer-events: none;', $css);
    assert_contains('z-index: 0;', $css);
    assert_contains('.newsdetail-gallery__slide.is-active { opacity: 1; pointer-events: auto; z-index: 1; }', $css);
    assert_contains('.newsdetail-gallery__main { position: relative; aspect-ratio: 16/9; border-radius: var(--radius, 14px); overflow: hidden; background: #16283f; cursor: zoom-in; }', $css);
});

test('Frontend JS: лайтбокс галереи новости открывает именно активный слайд и синхронизирует навигацию', function () {
    $root = dirname(__DIR__, 2);
    $js = (string) file_get_contents($root . '/public/assets/js/frontend.js');

    assert_contains('root.__ndgShow = show;', $js, 'Слайдер должен публиковать функцию переключения слайда');
    assert_contains("var activeImg = gallery.querySelector('.newsdetail-gallery__slide.is-active');", $js, 'Клик по фото должен разрешаться в активный слайд галереи');
    assert_contains("[data-ndgallery] .newsdetail-gallery__main", $js, 'Контейнер слайдера должен открывать активный слайд');
    assert_contains("typeof gallery.__ndgShow === 'function'", $js, 'Навигация в лайтбоксе должна синхронизировать слайдер');
});
