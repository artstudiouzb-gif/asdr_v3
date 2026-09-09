<?php

declare(strict_types=1);

// Четыре правила, каждое из которых закрывает измеренный провал контраста или
// перекрытие. Тест держит их на месте: все четыре ломаются молча — вёрстка
// остаётся «нормальной на вид», а цифры уходят ниже нормы WCAG AA.

test('Выделение в заголовке подмешивается к цвету текста, а не берётся готовым', function () {
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    // Готовый --gov-teal-text посчитан для светлой поверхности. На тёмной
    // (обложка новости, hero, navy-секция) он давал 2.66:1 при норме 3:1.
    // color-mix с currentColor тянет акцент к читаемому: замерено 6.45:1 на
    // светлой теме, 8.21:1 на тёмной, 4.57:1 на стеклянной панели поверх фото.
    assert_contains('body.design-mark-accent .tx-mark', $theme);
    $rule = (string) preg_replace('/^.*?body\.design-mark-accent \.tx-mark\s*\{(.*?)\}.*$/s', '$1', $theme);
    assert_contains('color-mix', $rule, 'акцент выделения снова взят готовым цветом');
    assert_contains('currentColor', $rule, 'без currentColor выделение не подстроится под тёмную поверхность');
});

test('Примечание показателя не приглушается прозрачностью', function () {
    // Счётчики вынесены из общей темы в blocks/counters.css — берём склейку.
    $theme = theme_css();
    $rule = (string) preg_replace('/^.*?\.counter__note\s*\{(.*?)\}.*$/s', '$1', $theme);

    // --gov-muted подобран впритык к 4.5:1; множитель 0.8 сажал его до 3.51:1.
    // Разница с подписью держится кеглем.
    assert_not_contains('opacity', $rule, 'прозрачность у примечания снова роняет контраст ниже 4.5:1');
    assert_contains('--step--3', $rule, 'примечание обязано отличаться от подписи размером');
});

test('Метка рубрики на фото-hero перебивает базовое правило', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');

    // В теме .newsdetail__badge--onDark объявлен ВЫШЕ базового
    // .newsdetail__badge и при равной специфичности проигрывал ему: метка
    // получала акцент для светлой подложки и давала 2.10:1 при норме 4.5:1.
    $modifier = strpos($theme, '.newsdetail__badge--onDark');
    $base = strpos($theme, '.newsdetail__badge {');
    assert_true(
        $modifier !== false && $base !== false && $modifier < $base,
        'порядок правил в теме изменился — проверьте, нужна ли ещё надбавка специфичности'
    );
    assert_contains(
        '.newsdetail-phero .newsdetail__badge--onDark',
        $css,
        'без надбавки специфичности метка на фото снова станет нечитаемой'
    );
});

test('Крошки на узком экране возвращаются в поток', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');

    // Вынутые из потока крошки переносятся на 2–3 строки и ложатся поверх
    // метки: замерено 30px перекрытия на 640px и ниже.
    assert_contains('.newsdetail-phero .content-crumbs', $css);
    assert_contains('position: static', $css, 'крошки снова перекроют плашки на телефоне');

    // Граница — из общей шкалы точек перелома публички (тест 232).
    assert_contains('@media (max-width: 640px)', $css);
});

test('Заголовок обложки-карточки остаётся плавным', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/news-detail.css');
    $rule = (string) preg_replace(
        '/^.*?\.newsdetail-phero--card \.newsdetail-phero__title\s*\{(.*?)\}.*$/s',
        '$1',
        $css
    );

    // Статичная ступень оставляла на 320px те же 36px, что и на десктопе.
    assert_contains('clamp(', $rule, 'кегль заголовка карточки снова статичный');
});
