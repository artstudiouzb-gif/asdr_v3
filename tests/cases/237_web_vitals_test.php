<?php

declare(strict_types=1);

use App\Core\WebVitals;

test('Web Vitals: оценка по порогам web.dev', function (): void {
    assert_same('good', WebVitals::rate('LCP', 2000.0));
    assert_same('needs-improvement', WebVitals::rate('LCP', 3000.0));
    assert_same('poor', WebVitals::rate('LCP', 5000.0));
    assert_same('good', WebVitals::rate('CLS', 0.05));
    assert_same('poor', WebVitals::rate('CLS', 0.4));
    assert_same('good', WebVitals::rate('INP', 150.0));
    assert_same('poor', WebVitals::rate('INP', 900.0));
    // Незнакомая метрика оценки не получает — и в базу не попадёт.
    assert_same('', WebVitals::rate('XYZ', 1.0));
});

test('Web Vitals: адрес страницы не собирается, только крупный тип', function (): void {
    assert_same('news', WebVitals::pageKind('site/news_show'));
    assert_same('news_list', WebVitals::pageKind('site/news_index'));
    assert_same('page', WebVitals::pageKind('site/page'));
    assert_same('search', WebVitals::pageKind('site/search'));
    assert_same('other', WebVitals::pageKind('site/translation_unavailable'));
    assert_same('other', WebVitals::pageKind(''));
});

test('Web Vitals: доля выборки держится в разумных границах', function (): void {
    reset_design_state();
    // Значение из настроек может быть любым; наружу выходит только 0.01–1.
    $rate = WebVitals::sampleRate();
    assert_true($rate > 0 && $rate <= 1.0, 'доля выборки в пределах 0..1, получено ' . $rate);
});

test('Web Vitals: приём метрик не хранит персональных данных', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/VitalsController.php');
    $core = (string) file_get_contents(APP_ROOT . '/app/Core/WebVitals.php');
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');

    // IP годится только как ключ ограничителя частоты.
    assert_not_contains('REMOTE_ADDR', $core, 'адрес посетителя не должен доходить до хранилища');
    foreach (['ip', 'user_agent', 'referer', 'url'] as $column) {
        assert_false(
            preg_match('/CREATE TABLE IF NOT EXISTS web_vitals \(.*?\b' . $column . '\b.*?\n\)/s', $schema) === 1,
            'в таблице web_vitals не должно быть колонки ' . $column
        );
    }
    // Сессию заводить нельзя: она делает ответ некэшируемым для всей публички.
    assert_not_contains('Session::start', $controller);
    assert_not_contains('Csrf::', $controller);
});

test('Web Vitals: ручка исключена из общего кеша и подключена маршрутом', function (): void {
    $cache = (string) file_get_contents(APP_ROOT . '/app/Core/PublicResponseCache.php');
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');

    assert_contains("'/_vitals'", $cache, 'ручка не должна попадать в общий кеш');
    assert_contains("post('/_vitals'", $routes);
    assert_false(
        WebVitals::enabled() && Setting_missing(),
        'сбор по умолчанию выключен'
    );
});

/** Помощник: настройка сбора по умолчанию отсутствует, значит сбор выключен. */
function Setting_missing(): bool
{
    return \App\Models\Setting::get('perf_vitals_enabled', '') === '';
}
