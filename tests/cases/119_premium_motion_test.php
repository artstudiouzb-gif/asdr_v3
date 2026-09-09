<?php

declare(strict_types=1);

test('Главные CTA и преимущества используют доступные premium-анимации', function () {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');

    assert_contains('@keyframes hero-button-sheen', $css);
    assert_contains('.block-hero__button:not(.block-hero__button--ghost)::after', $css);
    assert_contains('animation: hero-button-sheen 6s ease-in-out infinite;', $css);
    assert_false(str_contains($css, 'animation: hero-button-sheen 4.2s'));
    assert_contains('@keyframes advantages-icon-float', $css);
    assert_contains('.block-advantages__item:focus-within .block-advantages__icon', $css);
    assert_contains('@media (prefers-reduced-motion: reduce)', $css);
    assert_contains('.block-hero__button::after { content: none; animation: none; }', $css);
});

test('Карусель проектов не отключает анимации своих карточек', function (): void {
    $govCss = theme_css();
    $frontendCss = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');

    assert_true(!str_contains($govCss, '[data-carousel-track] *'), 'Вложенные переходы карусели должны оставаться активными');
    assert_contains('transition: transform .7s cubic-bezier(.22, 1, .36, 1)', $govCss);
    // Сдвиг и масштаб появления живут в переменных: сам показ описан
    // ключевыми кадрами (переход карточки объявлен темой и заменял базовый).
    assert_contains('@keyframes card-reveal', $frontendCss);
    assert_contains('--card-reveal-shift: 18px', $frontendCss);
    assert_contains('--card-reveal-scale: .99;', $frontendCss);
    assert_contains('translateY(var(--card-reveal-shift)) scale(var(--card-reveal-scale))', $frontendCss);
});

test('Счётчики без стекла и поворота, новости появляются мягко', function (): void {
    $govCss = theme_css();
    $frontendCss = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/frontend.css');

    // Проверяем все объявления .block-counters, а не срез «до ближайшего
    // комментария»: прежний срез ломался от любой перестановки правил выше и
    // молча проверял чужой код.
    preg_match_all('/\.block-counters\s*\{([^}]*)\}/', $govCss, $countersMatches);
    $countersBodies = $countersMatches[1] ?? [];
    assert_true($countersBodies !== [], 'правило .block-counters должно существовать');
    assert_true(
        (bool) array_filter(
            $countersBodies,
            static fn (string $body): bool => str_contains($body, 'background: var(--counters-bg, var(--gov-surface))')
        ),
        'фон счётчиков берётся из настройки блока'
    );
    foreach ($countersBodies as $body) {
        assert_true(!str_contains($body, 'backdrop-filter'), 'У счётчиков не должно быть размытия стекла');
    }

    $iconStart = (int) strpos($govCss, '.counter__icon {');
    $iconEnd = (int) strpos($govCss, '.counter__icon svg', $iconStart);
    $iconCss = substr($govCss, $iconStart, $iconEnd - $iconStart);
    assert_true(!str_contains($iconCss, 'rotate('), 'Иконки счётчиков не должны поворачиваться');

    assert_contains('.newsfeat-grid > .anim-card', $frontendCss);
    assert_contains('--card-reveal-shift: 8px', $frontendCss);
    assert_contains('--card-reveal-scale: .995', $frontendCss);
    assert_contains(':where(.newsfeat-lead, .newsfeat-mini, .newsfeat-text):hover', $govCss);
    assert_contains('transform: translateY(-1px)', $govCss);
});

test('Медиакарточки наследуют hover feature-card и сохраняют zoom обложки', function (): void {
    $govCss = theme_css();

    assert_true(
        (bool) preg_match('/[^{}]*\\.mediacard\\.mediacard[^{}]*:hover[^{}]*\\{[^{}]*transform:\\s*translateY\\(var\\(--feature-card-hover-lift, -4px\\)\\)/s', $govCss),
        'Медиакарточка должна использовать настраиваемый подъём feature-card'
    );
    assert_contains('.mediacard:hover .mediacard__img', $govCss);
    assert_contains('transform: scale(1.05)', $govCss);
});

test('Подъём карточек допускает отключение и пользовательское значение', function (): void {
    assert_true(\App\Core\DesignSettings::normalizeCardHoverLift('0') === 0, '0 должен отключать подъём');
    assert_true(\App\Core\DesignSettings::normalizeCardHoverLift('4') === 4, '4px — стандартное значение');
    assert_true(\App\Core\DesignSettings::normalizeCardHoverLift('11') === 11, 'Пользовательское значение должно сохраняться');
    assert_true(\App\Core\DesignSettings::normalizeCardHoverLift('99') === 20, 'Значение ограничивается безопасным максимумом');
    assert_true(\App\Core\DesignSettings::normalizeCardHoverLift('-2') === 4, 'Некорректное значение возвращает стандартные 4px');
});
