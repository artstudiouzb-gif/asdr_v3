<?php

declare(strict_types=1);

// Гигиена публичного CSS. Два сторожа против того, из-за чего правки «не
// срабатывают»: мёртвых повторов одного селектора и роста !important.

/**
 * Разбирает CSS на правила с учётом вложенности @media/@supports: повтор
 * селектора внутри медиазапроса законен, а в одном контексте — нет.
 *
 * @return list<array{ctx:string, sel:string, body:string, line:int}>
 */
function css_rules(string $css): array
{
    $out = [];
    $stack = [];
    $len = strlen($css);
    $i = 0;
    $head = 0;
    while ($i < $len) {
        if ($css[$i] === '/' && substr($css, $i, 2) === '/*') {
            $end = strpos($css, '*/', $i);
            $i = $end === false ? $len : $end + 2;
            continue;
        }
        if ($css[$i] === '{') {
            $selector = substr($css, $head, $i - $head);
            // Комментарии между правилами в «голову» селектора не входят.
            $selector = trim((string) preg_replace('#/\*.*?\*/#s', '', $selector));
            if (str_starts_with($selector, '@')) {
                $stack[] = $selector;
                $i++;
                $head = $i;
                continue;
            }
            $depth = 1;
            $j = $i + 1;
            while ($j < $len && $depth > 0) {
                if ($css[$j] === '{') {
                    $depth++;
                } elseif ($css[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            $out[] = [
                'ctx' => implode(' | ', $stack),
                'sel' => (string) preg_replace('/\s+/', ' ', $selector),
                'body' => substr($css, $i + 1, $j - $i - 2),
                // Строку берём по позиции «{»: перед селектором мог стоять комментарий.
                'line' => substr_count($css, "\n", 0, $i) + 1,
            ];
            $i = $j;
            $head = $i;
            continue;
        }
        if ($css[$i] === '}') {
            array_pop($stack);
            $i++;
            $head = $i;
            continue;
        }
        $i++;
    }

    return $out;
}

/** @return array<string,string> имена свойств правила (без пользовательских) */
function css_props(string $body): array
{
    $props = [];
    foreach (explode(';', $body) as $decl) {
        if (!str_contains($decl, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $decl, 2);
        $name = strtolower(trim($name));
        if ($name !== '' && !str_starts_with($name, '--')) {
            $props[$name] = trim($value);
        }
    }

    return $props;
}

/*
 * `public_css_files()` объявлена в tests/budgets.php: тот же список файлов
 * меряет бюджет `!important`, и два глоба разъехались бы при первой правке.
 */

test('Публичный CSS не хранит мёртвых повторов одного селектора', function () {
    $dead = [];
    foreach (public_css_files() as $path) {
        $groups = [];
        foreach (css_rules((string) file_get_contents($path)) as $rule) {
            $groups[$rule['ctx'] . '###' . $rule['sel']][] = $rule;
        }
        foreach ($groups as $items) {
            if (count($items) < 2) {
                continue;
            }
            for ($idx = 0; $idx < count($items) - 1; $idx++) {
                $mine = css_props($items[$idx]['body']);
                if ($mine === []) {
                    continue;
                }
                $later = [];
                foreach (array_slice($items, $idx + 1) as $next) {
                    $later += css_props($next['body']);
                    $later = array_merge($later, css_props($next['body']));
                }
                // Все свойства заданы заново ниже — при равной специфичности
                // выигрывает последнее объявление, значит это правило мёртвое.
                $overridden = array_intersect(array_keys($mine), array_keys($later));
                if (count($overridden) === count($mine)) {
                    $dead[] = basename($path) . ':' . $items[$idx]['line'] . ' ' . $items[$idx]['sel'];
                }
            }
        }
    }

    assert_same([], $dead, "мёртвые повторы (правило ниже перекрывает целиком):\n      " . implode("\n      ", $dead));
});

test('Правило из базового файла не дублируется темой целиком', function () {
    // Порядок подключения задаёт FrontendAssets::CSS_SOURCES: frontend.css идёт
    // до gov-theme.css, поэтому при равной специфичности выигрывает тема. Копия
    // в базе выглядит действующей, но не действует — правку в ней редактор
    // вносит впустую. Так в базе жили целые куски FAQ и контактных карточек.
    $sources = (string) file_get_contents(APP_ROOT . '/app/Core/FrontendAssets.php');
    assert_true(
        strpos($sources, "/assets/css/frontend.css") < strpos($sources, "/assets/css/gov-theme.css"),
        'тема подключается после базы — на этом держится проверка'
    );

    $later = [];
    foreach (css_rules((string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css')) as $rule) {
        $key = $rule['ctx'] . '###' . $rule['sel'];
        $later[$key] = array_merge($later[$key] ?? [], css_props($rule['body']));
    }

    $base = [];
    foreach (css_rules((string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css')) as $rule) {
        $key = $rule['ctx'] . '###' . $rule['sel'];
        $base[$key]['props'] = array_merge($base[$key]['props'] ?? [], css_props($rule['body']));
        $base[$key]['line'] = $base[$key]['line'] ?? $rule['line'];
        $base[$key]['sel'] = $rule['sel'];
    }

    $dead = [];
    foreach ($base as $key => $info) {
        if (!isset($later[$key]) || ($info['props'] ?? []) === []) {
            continue;
        }
        $missing = array_diff(array_keys($info['props']), array_keys($later[$key]));
        if ($missing === []) {
            $dead[] = 'frontend.css:' . $info['line'] . ' ' . $info['sel'];
        }
    }

    assert_same([], $dead, "тема перекрывает целиком:\n      " . implode("\n      ", $dead));
});

test('Число !important в публичном CSS не растёт', function () {
    // Потолок и замер объявлены в tests/budgets.php: оттуда же их читает отчёт
    // `php .claude/skills/quality-budgets/report.php`, показывающий запас до
    // падения. Значение может только уменьшаться: каждый !important это правка,
    // не выигравшая по специфичности, и следующая поверх него становится
    // непредсказуемой. Оставшиеся держат поведение, а не оформление: скрытие
    // [hidden], тач-цели шапки, вкладки галереи, появление карточек, контраст
    // активного пункта меню (4.5:1), цвет счётчиков, режимы доступности.
    $budget = quality_budget('public_important');

    assert_true(
        $budget['value'] <= $budget['ceiling'],
        'стало ' . $budget['value'] . ' при потолке ' . $budget['ceiling']
            . '; больше всего: ' . $budget['detail']
    );
});

test('Вынесенная часть темы не переопределяется поздними файлами бандла', function () {
    // Части темы подключаются ПОСЛЕ общего бандла (AssetCollector::
    // renderThemeStyles), поэтому вынос семейства правил поднимает его в
    // каскаде выше public-layout-polish и editorial. Если те переопределяют
    // те же селекторы, вынос молча меняет вид: так «Этапы» после выноса
    // получили padding-top 24px вместо 28px, заданных polish.
    $collector = (string) file_get_contents(APP_ROOT . '/app/Core/AssetCollector.php');
    preg_match_all("#'([a-z_]+)' => '(/assets/css/blocks/[a-z-]+\.css)'#", $collector, $m, PREG_SET_ORDER);
    assert_true($m !== [], 'карта частей темы разобрана');

    $lateFiles = ['public-layout-polish.css', 'public-editorial-pages.css', 'public-content-modes.css'];
    $late = '';
    foreach ($lateFiles as $name) {
        $late .= (string) file_get_contents(APP_ROOT . '/public/assets/css/' . $name) . "\n";
    }
    // Считаем конфликтом не сам повтор селектора, а повтор ВМЕСТЕ со свойством:
    // polish вправе задать вынесенной карточке фильтр фотографии, лишь бы он не
    // спорил с тем, что объявляет сама часть темы.
    $lateSelectors = [];
    foreach (css_rules($late) as $rule) {
        foreach (explode(',', $rule['sel']) as $part) {
            $part = trim($part);
            $lateSelectors[$part] = array_merge($lateSelectors[$part] ?? [], array_keys(css_props($rule['body'])));
        }
    }

    $clashes = [];
    foreach ($m as [, $key, $href]) {
        $path = APP_ROOT . '/public' . $href;
        if (!is_file($path)) {
            $clashes[] = $key . ': файла ' . $href . ' нет';
            continue;
        }
        foreach (css_rules((string) file_get_contents($path)) as $rule) {
            $mine = array_keys(css_props($rule['body']));
            foreach (explode(',', $rule['sel']) as $part) {
                $part = trim($part);
                if ($part === '' || !isset($lateSelectors[$part])) {
                    continue;
                }
                $shared = array_intersect($mine, $lateSelectors[$part]);
                if ($shared !== []) {
                    $clashes[] = basename($href) . ' ↔ поздний файл: ' . $part
                        . ' (' . implode(', ', array_slice($shared, 0, 3)) . ')';
                }
            }
        }
    }

    $clashes = array_values(array_unique($clashes));
    assert_same([], $clashes, "вынос меняет каскад:\n      " . implode("\n      ", $clashes));
});

test('Режимный класс на body не перебивает компонентные классы', function () {
    // `body.design-type-static h2` весит (0,2,1) и выигрывает у любого
    // одиночного класса компонента: заголовок карточки каталога рисовался
    // размером H2 (32px вместо 20px), заголовок обложки — 42px вместо 56px,
    // а заголовок панели настроек — 32px вместо 16px. Режим — это
    // переключатель, а не повод добавлять вес: квалификатор оборачивается в
    // `:where()`, и правило весит ровно столько, сколько сам селектор.
    // Правило вида `body.design-type-static .block-cta h2` не в счёт: там тег
    // уже сужен компонентным классом.
    $bad = [];
    foreach (public_css_files() as $path) {
        foreach (css_rules((string) file_get_contents($path)) as $rule) {
            foreach (explode(',', $rule['sel']) as $part) {
                $part = trim($part);
                if (preg_match('/^body\.design-[a-z0-9_-]+\s+[a-z]+[0-9]?(\s|$)/i', $part) === 1) {
                    $bad[] = basename($path) . ':' . $rule['line'] . ' ' . $part;
                }
            }
        }
    }

    assert_same([], $bad, "режим добавляет вес голому тегу:\n      " . implode("\n      ", $bad));
});

test('Переход между страницами: шапка переносится, а не перерисовывается', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');
    $min = (string) file_get_contents(APP_ROOT . '/public/assets/css/public.min.css');

    // Опт-ин обязан пережить минификатор: сокращения и незнакомые at-rule он
    // уже выбрасывал (см. transition с переменной), и заметить это можно только
    // в собранном файле.
    assert_contains('@view-transition', $css);
    assert_contains('@view-transition{navigation:auto}', $min, 'минификатор выбросил опт-ин перехода');
    assert_contains('view-transition-name:site-header', $min);

    // Шапка не липкая, и по умолчанию браузер вёз бы её снимок от старого
    // положения к новому: уходя с прокрутки, старое положение выше экрана —
    // шапка влетала бы сверху, оставляя верх страницы пустым.
    assert_contains('::view-transition-group(site-header)', $css);
    assert_contains('::view-transition-group(site-header),::view-transition-group(site-topbar){animation:none}', $min);

    // И не переливается со старой. Вид шапки зависит от страницы: на обложке
    // она прозрачная со светлым набором, на остальных — сплошная. Перелив двух
    // таких снимков держал белую полосу поверх уже тёмной обложки — это и
    // читалось как мигание белой шапки при переходе на главную.
    assert_contains('::view-transition-old(site-header),::view-transition-old(site-topbar){animation:none;opacity:0}', $min);
    assert_contains('::view-transition-new(site-header),::view-transition-new(site-topbar){animation:none;opacity:1}', $min);

    // Подвалу имя не даём: внизу страницы он то виден, то нет, и снимок ехал бы
    // за экран на глазах у читателя.
    assert_false(str_contains($css, 'view-transition-name: site-footer'), 'подвал не должен участвовать в переходе');
});

test('Переход между страницами уважает «меньше движения»', function (): void {
    // Псевдоэлементы перехода живут отдельным деревом у корня, и общее правило
    // a11y `html[data-a11y-motion="off"] *` их не достаёт — нужен свой селектор.
    $a11y = (string) file_get_contents(APP_ROOT . '/public/assets/css/a11y.css');

    assert_contains('@media (prefers-reduced-motion: reduce)', $a11y);
    assert_contains('html::view-transition-group(*)', $a11y);
    assert_contains('html[data-a11y-motion="off"]::view-transition-group(*)', $a11y);

    $min = (string) file_get_contents(APP_ROOT . '/public/assets/css/public.min.css');
    assert_contains('html[data-a11y-motion=off]::view-transition-group(*)', $min);
});
