<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Адаптивная карусель подключена только к подходящим повторяющимся блокам', function (): void {
    $stages = BlockRenderer::render([
        'id' => 17601,
        'type' => 'stages',
        'data' => json_encode([
            'title' => 'Этапы',
            'items' => array_fill(0, 6, [
                'stage' => 'Этап',
                'title' => 'Работа',
                'status' => 'planned',
            ]),
        ], JSON_UNESCAPED_UNICODE),
    ])['html'];
    assert_contains('data-carousel', $stages);
    assert_contains('stages--carousel', $stages);
    assert_contains('data-carousel-track', $stages);
    assert_contains('data-carousel-dots', $stages);

    $partners = BlockRenderer::render([
        'id' => 17602,
        'type' => 'partners',
        'data' => json_encode([
            'title' => 'Партнёры',
            'items' => array_fill(0, 7, ['name' => 'Организация']),
        ], JSON_UNESCAPED_UNICODE),
    ])['html'];
    assert_contains('block-partners__grid--carousel', $partners);
    assert_contains('data-carousel-item', $partners);

    $docs = BlockRenderer::render([
        'id' => 17603,
        'type' => 'docs_list',
        'data' => json_encode([
            'title' => 'Документы',
            'items' => array_fill(0, 8, ['title' => 'Документ']),
        ], JSON_UNESCAPED_UNICODE),
    ])['html'];
    assert_not_contains('data-carousel', $docs, 'важные списки не должны скрываться за каруселью');
});

test('Единый движок управляет переполнением, клавиатурой и reduced motion', function (): void {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    $css = theme_css();

    assert_contains("querySelectorAll('[data-carousel]')", $js);
    assert_contains("event.key === 'ArrowLeft'", $js);
    assert_contains("event.key === 'Home'", $js);
    assert_contains("prefers-reduced-motion: reduce", $js);
    assert_contains("positions = pagePositions()", $js);
    assert_contains('MAX_PROGRESS_DOTS = 7', $js, 'индикаторы не должны переполнять мобильную шапку');
    assert_contains('scrollToPosition', $js, 'переключение карточек должно использовать единое плавное движение');
    assert_contains('duration = 620', $js, 'переход между карточками не должен быть резким');
    assert_contains('Math.cos(progress * Math.PI)', $js, 'движение должно мягко ускоряться и замедляться');
    assert_not_contains("track.querySelector('.imgcard')", $js, 'движок не должен зависеть от одного типа карточки');

    assert_contains('.carousel-nav__dot.is-active', $css);
    assert_contains('.stages[data-carousel-track]', $css);
    assert_contains('.imgcards-grid--mobile-carousel', $css);
    assert_contains('@media (prefers-reduced-motion: reduce)', $css);
});

test('Ход полосы никто не перебивает: ни плавность CSS, ни привязка карточек', function (): void {
    // Ход считает frontend.js покадрово, и на том же элементе за прокрутку
    // тянут ещё двое. `scroll-behavior: smooth` превращает каждое присвоение
    // scrollLeft в отдельную браузерную анимацию — наша ползла по 2px за кадр
    // и не доходила до цели (замерено 638 из 820). Обязательная привязка
    // (scroll-snap) подтягивает каждый промежуточный кадр к ближайшей карточке
    // — ход распадался на прыжки 0 → 205 → 410 → 615 → 820. Оба отказа видны
    // только глазом на живой странице, поэтому проверяются здесь.
    $files = [APP_ROOT . '/public/assets/css/frontend.css'];
    foreach (glob(APP_ROOT . '/public/assets/css/*.css') ?: [] as $file) {
        if (!str_ends_with($file, '.min.css')) {
            $files[] = $file;
        }
    }
    foreach (glob(APP_ROOT . '/public/assets/css/blocks/*.css') ?: [] as $file) {
        if (!str_ends_with($file, '.min.css')) {
            $files[] = $file;
        }
    }

    $offenders = [];
    foreach (array_unique($files) as $file) {
        $css = (string) @file_get_contents($file);
        if (preg_match_all('/([^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER) === false) {
            continue;
        }
        foreach ($rules as $rule) {
            $selector = trim($rule[1]);
            $isTrack = str_contains($selector, 'carousel')
                || str_contains($selector, 'cards-track')
                || str_contains($selector, '__track');
            if (!$isTrack) {
                continue;
            }
            if (preg_match('/scroll-behavior\s*:\s*smooth/', $rule[2]) === 1) {
                $offenders[] = basename($file) . ' → ' . $selector;
            }
        }
    }

    assert_same([], $offenders, 'у полосы карусели снова своя плавность CSS: ' . implode(', ', $offenders));

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    assert_contains("track.style.scrollSnapType = 'none'", $js, 'привязка обязана сниматься на время своей анимации');
    assert_contains('releaseSnap();', $js, 'снятие привязки должно идти вместе с запуском анимации');
    assert_contains("track.addEventListener('pointerdown', yieldToUser", $js, 'привязка обязана вернуться к прокрутке посетителя');
    assert_contains("track.addEventListener('touchstart', yieldToUser", $js);
    assert_contains("track.addEventListener('wheel', yieldToUser", $js);
});
