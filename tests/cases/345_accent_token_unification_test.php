<?php

declare(strict_types=1);

use App\Core\AccentContrast;

/*
 * Один цвет — одно имя и одно запасное значение.
 *
 * Бренд-акцент и основной цвет настраиваются в админке (--color-accent /
 * --color-primary, печатает App\Core\SiteThemeCss). В публичном CSS их роли
 * названы --gov-teal, --gov-teal-text и --gov-navy, и объявлены они ровно
 * один раз — в :root темы.
 *
 * Зачем тест. Правило вида `var(--color-accent, #e63946)` выглядит
 * предусмотрительным, но запасное значение срабатывает только там, где
 * переменной нет, — то есть на живом сайте не срабатывает никогда и потому
 * никем не проверяется. Такие «умолчания» и разъехались: у одного и того же
 * акцента их набралось пять (#0ea5e9, #17999b, #e63946, #5b52e5, #2f6fed), у
 * основного цвета — шесть, а у акцентного текста — восемь, включая
 * бирюзовые, синие и красные тона одновременно. Плюс `--gov-teal-dark`,
 * которого не существовало вовсе: три роли темы держались на его запасном
 * значении.
 *
 * Отсюда правило: точка использования пишет голое `var(--gov-teal)` /
 * `var(--gov-teal-text)` / `var(--gov-navy)`, а число живёт в :root. Если
 * переменной там не окажется, страница сломается заметно и сразу — это лучше
 * тихого расхождения оттенков по файлам.
 */

/** @return array<string, string> Исходники публичного CSS: путь => содержимое. */
function accent_public_css_sources(): array
{
    $out = [];
    foreach ([
        APP_ROOT . '/public/assets/css/*.css',
        APP_ROOT . '/public/assets/css/blocks/*.css',
    ] as $pattern) {
        foreach ((array) glob($pattern) as $file) {
            $file = (string) $file;
            if (str_ends_with($file, '.min.css')) {
                continue; // Сборка, а не исходник.
            }
            $out[substr($file, strlen(APP_ROOT) + 1)] = (string) file_get_contents($file);
        }
    }

    return $out;
}

test('Настройки цвета читаются только через роли темы, а не напрямую', function () {
    $theme = 'public/assets/css/gov-theme.css';
    $allowed = [
        '--gov-teal: var(--color-accent, #17999b);',
        '--gov-navy: var(--color-primary, #0f2756);',
    ];

    foreach (accent_public_css_sources() as $path => $css) {
        if ($path === $theme) {
            // В теме объявления есть, но ровно два и ровно эти.
            foreach ($allowed as $decl) {
                assert_contains($decl, $css, "в теме нет канонического объявления: $decl");
            }
            $css = str_replace($allowed, '', $css);
        }

        assert_same(
            0,
            preg_match_all('/var\(\s*--color-(?:accent|primary)\b/', $css),
            "$path: цвет из админки читается напрямую — используйте var(--gov-teal) / var(--gov-navy)"
        );
    }
});

test('Роли цвета используются без запасных значений', function () {
    $roles = ['--gov-teal', '--gov-teal-text', '--gov-navy', '--accent-fill-strong', '--gov-teal-on-light'];

    foreach (accent_public_css_sources() as $path => $css) {
        foreach ($roles as $role) {
            $count = preg_match_all('/var\(\s*' . preg_quote($role, '/') . '\s*,/', $css);
            assert_same(
                0,
                $count,
                "$path: у var($role, …) есть запасное значение — оно недостижимо и молча разъедется с :root"
            );
        }
    }
});

test('Запасные значения ролей посчитаны от акцента по умолчанию, а не на глаз', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    $rootAt = (int) strpos($css, ':root {');
    $root = substr($css, $rootAt, (int) strpos($css, "\n}", $rootAt) - $rootAt);

    $token = static function (string $name) use ($root): string {
        preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $root, $m);

        return strtolower(trim($m[1] ?? ''));
    };

    // Акцент по умолчанию — источник, всё остальное считается от него тем же
    // кодом, что и на сервере (SiteThemeCss печатает эти же роли).
    preg_match('/--gov-teal:\s*var\(--color-accent,\s*(#[0-9a-f]{6})\)/i', $root, $m);
    $accent = strtolower($m[1] ?? '');
    assert_same('#17999b', $accent, 'акцент по умолчанию объявлен один раз в :root');

    $onLight = strtolower(AccentContrast::onLight($accent, '#ffffff'));
    assert_same($onLight, $token('--gov-teal-on-light'), '--gov-teal-on-light должен быть AccentContrast::onLight(акцент, белый)');
    assert_same($onLight, $token('--accent-fill-strong'), '--accent-fill-strong считается так же: белый на нём обязан читаться');

    // Текстовая роль не пишет своего числа — она берёт вариант для светлой
    // поверхности. Так тёмная секция переопределяет её, а белая карточка
    // внутри секции возвращает читаемый цвет по имени, которого секция не трогает.
    assert_same('var(--gov-teal-on-light)', $token('--gov-teal-text'), '--gov-teal-text объявлен через --gov-teal-on-light');

    assert_true(
        AccentContrast::ratio($onLight, '#ffffff') >= AccentContrast::AA_NORMAL,
        'запасной акцентный текст обязан держать 4.5:1 на белом'
    );
    assert_true(
        AccentContrast::ratio($accent, '#ffffff') < AccentContrast::AA_NORMAL,
        'сам акцент для текста не годится — именно поэтому у роли отдельное значение'
    );
});

test('Фантомных токенов в теме не осталось', function () {
    foreach (accent_public_css_sources() as $path => $css) {
        assert_same(
            0,
            substr_count($css, '--gov-teal-dark'),
            "$path: --gov-teal-dark никогда не объявлялся — правило держалось на его запасном значении"
        );
    }
});
