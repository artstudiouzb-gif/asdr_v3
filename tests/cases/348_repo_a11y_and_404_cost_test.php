<?php

declare(strict_types=1);

use App\Core\A11ySettings;
use App\Models\NotFoundLog;

/**
 * Три места, где мёртвый код выглядел рабочим: настройки отображения на
 * портале, общий кеш поверх персонализированного ответа и поиск по сайту на
 * каждый скан.
 */

test('Портал репозитория читает настройки отображения общим классом', function (): void {
    $top = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/repo/layout/top.php');

    assert_contains('A11ySettings::fromCookie', $top, 'формат cookie знает один класс');
    assert_contains('A11ySettings::htmlAttributes', $top, 'страница обязана приходить уже настроенной');

    // Прежний формат «cw:l:on» и его атрибуты: ни того, ни другого больше нет
    // ни в A11ySettings, ни в a11y.css — условие не срабатывало никогда.
    assert_not_contains("explode(':'", $top, 'разбор cookie прежней версии');
    // Именно в разметке: в пояснении рядом это имя упоминается нарочно.
    assert_not_contains('data-a11y-scheme="', $top, 'такого атрибута в a11y.css нет');

    // Кнопку слушает a11y.js по data-a11y-open; без него она ничего не делала.
    assert_contains('data-a11y-open', $top);
    assert_contains("require dirname(__DIR__, 2) . '/site/_a11y_panel.php'", $top, 'панель одна на сайт и портал');

    // Экран входа тоже: настройки задаются на сайте, а логинятся здесь.
    foreach (['login.php', 'login_2fa.php'] as $view) {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/repo/' . $view);
        assert_contains('A11ySettings::htmlAttributes', $src, $view . ': экран входа без настроек отображения');
        assert_contains('/assets/css/a11y.css', $src, $view . ': атрибуты без правил ничего не меняют');
    }
});

test('Панель настроек портала не держит своей копии кнопок', function (): void {
    $top = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/repo/layout/top.php');

    // «size:m|l|xl» нормализатор уже не знает: (int)'l' = 0, значение
    // упиралось бы в нижнюю границу, то есть «Крупный» сбрасывал бы размер.
    foreach (['data-a11y-set="size:m"', 'data-a11y-set="size:l"', 'data-a11y-set="scheme:cw"'] as $dead) {
        assert_not_contains($dead, $top, 'кнопка из прошлой версии настроек');
    }

    // И убеждаемся, что нормализатор действительно их не принимает, —
    // иначе проверка выше стережёт вчерашнюю причину.
    $normalized = A11ySettings::normalize(['size' => 'l', 'scheme' => 'cw']);
    assert_same(A11ySettings::DEFAULTS['size'], $normalized['size'], 'размер теперь в процентах');
    assert_false(array_key_exists('scheme', $normalized), 'ключа scheme в настройках нет');
});

test('Персонализированный ответ не уходит в общий кеш', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/PublicResponseCache.php');

    assert_contains('A11ySettings::isActive', $src, 'сервер печатает data-a11y-* — ответ отличается от обычного');
    assert_contains("Cache-Control: private, max-age=%d", $src, 'такому ответу место только в кеше браузера');

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
