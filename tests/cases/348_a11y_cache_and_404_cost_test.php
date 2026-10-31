<?php

declare(strict_types=1);

use App\Core\A11ySettings;
use App\Models\NotFoundLog;

/**
 * Два места, где мёртвый код выглядел рабочим: общий кеш поверх
 * персонализированного ответа и поиск по сайту на каждый скан.
 */

test('Персонализированный ответ не уходит в общий кеш', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/PublicResponseCache.php');

    assert_contains('A11ySettings::isActive', $src, 'сервер печатает data-a11y-* — ответ отличается от обычного');
    assert_same('private, no-cache', \App\Core\PublicResponseCache::cacheControl(false, 300), 'такому ответу место только в кеше браузера');

    // Vary: Cookie фрагментирует общий кеш по любой cookie, включая метки
    // аналитики: попадания стали бы единичными. Он остаётся только там, где
    // ответ и правда зависит от cookie, — на узбекской кириллице.
    // Смотрим только на отправляемые заголовки: в пояснении рядом «Vary:
    // Cookie» упомянут как раз затем, чтобы объяснить, почему его нет.
    $varyHeaders = preg_grep('/header\(.*Vary:/', explode("\n", $src)) ?: [];
    assert_true($varyHeaders !== [], 'заголовок Vary должен выставляться');
    foreach ($varyHeaders as $line) {
        assert_contains('$varyCookie', $line, 'Vary по cookie — только под условием языка');
    }
});

test('Страница 404 не ищет по сайту для сканерских адресов', function (): void {
    // Знание «что такое скан» лежит в одном месте: второй список разъехался бы.
    assert_true(NotFoundLog::isNoise('/wp-login.php'), 'типовой скан');
    assert_true(NotFoundLog::isNoise('/.env'), 'попытка забрать конфигурацию');
    assert_true(NotFoundLog::isNoise('/assets/img/logo.png'), 'битая ссылка на статику');
    assert_true(NotFoundLog::isNoise('/wp-admin/setup-config.php'));
    assert_false(NotFoundLog::isNoise('/o-agentstve'), 'обычный адрес — человеку подсказка нужна');
    assert_false(NotFoundLog::isNoise('/news/kadrovyy-rezerv'));

    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/errors/404.php');
    assert_contains('NotFoundLog::isNoise($reqPath)', $view, 'поиск по сайту — десяток запросов на каждый промах');
});
