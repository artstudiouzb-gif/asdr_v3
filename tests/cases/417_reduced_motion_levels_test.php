<?php

declare(strict_types=1);

/*
 * «Уменьшить движение» и «остановка анимаций» — два разных уровня.
 *
 * Системная настройка просит убрать перемещение (сдвиг, масштаб, вращение,
 * прокрутку), а не любую анимацию: прежнее сжатие всех анимаций и переходов
 * до 0.01ms делало сайт на служебных компьютерах с выключенными «эффектами
 * анимации» мёртвым. Правило на все элементы не умеет отличить один
 * @keyframes от другого, поэтому кадры со сдвигом переопределяются по
 * одному — и этот тест следит, что ни один не забыт: забытый поехал бы ровно
 * у тех, кому движение противопоказано.
 *
 * Читается собранный CSS — то, что получает браузер: из двух одноимённых
 * @keyframes действует последний, и важен порядок в бандле, а не в исходнике.
 */

const REDUCED_MOTION_QUERY = 'prefers-reduced-motion:reduce';

/**
 * Определения @keyframes по порядку: имя, лежит ли внутри «уменьшить
 * движение», и какие свойства анимирует.
 *
 * @return list<array{name: string, reduced: bool, props: list<string>}>
 */
function motion_keyframes(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $found = [];
    $stack = [];
    $prelude = '';
    $length = strlen($css);
    for ($i = 0; $i < $length; $i++) {
        $ch = $css[$i];
        if ($ch === '{') {
            $head = trim($prelude);
            $prelude = '';
            if (preg_match('~^@(?:-webkit-)?keyframes\s+([\w-]+)$~', $head, $m) === 1) {
                $depth = 1;
                $start = $i + 1;
                while ($depth > 0 && ++$i < $length) {
                    $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
                }
                $body = substr($css, $start, $i - $start);
                preg_match_all('~([\w-]+)\s*:~', (string) preg_replace('~[^{};]*\{~', ';', $body), $props);
                $found[] = [
                    'name' => $m[1],
                    'reduced' => in_array(true, array_map(
                        static fn (string $at): bool => str_contains(str_replace(' ', '', $at), REDUCED_MOTION_QUERY),
                        $stack
                    ), true),
                    'props' => array_values(array_unique($props[1])),
                ];
                continue;
            }
            $stack[] = $head;
        } elseif ($ch === '}') {
            array_pop($stack);
            $prelude = '';
        } elseif ($ch === ';') {
            $prelude = '';
        } else {
            $prelude .= $ch;
        }
    }

    return $found;
}

/** Анимирует ли набор свойств что-то кроме прозрачности. Своя переменная — заглушка неподвижности. */
function motion_moves(array $props): bool
{
    foreach ($props as $prop) {
        if ($prop !== 'opacity' && !str_starts_with($prop, '--')) {
            return true;
        }
    }

    return false;
}

/**
 * Кадры со сдвигом, которые при «уменьшить движение» остаются как есть: нет
 * переопределения после последнего обычного определения или оно само двигает.
 *
 * @return list<string>
 */
function motion_unguarded(string $css): array
{
    $last = [];
    foreach (motion_keyframes($css) as $index => $frame) {
        if (!$frame['reduced']) {
            $last[$frame['name']] = ['index' => $index, 'moves' => motion_moves($frame['props'])];
        }
    }
    $frames = motion_keyframes($css);
    $unguarded = [];
    foreach ($last as $name => $definition) {
        if (!$definition['moves']) {
            continue;
        }
        $guarded = false;
        foreach ($frames as $index => $frame) {
            if ($index > $definition['index'] && $frame['reduced'] && $frame['name'] === $name) {
                $guarded = !motion_moves($frame['props']);
            }
        }
        if (!$guarded) {
            $unguarded[] = $name;
        }
    }

    return $unguarded;
}

/** @return array<string, string> путь => собранный CSS (бандл и файлы блоков) */
function motion_built_css(): array
{
    $files = ['public.min.css' => (string) file_get_contents(APP_ROOT . '/public/assets/css/public.min.css')];
    foreach (glob(APP_ROOT . '/public/assets/css/blocks/*.min.css') ?: [] as $file) {
        $files['blocks/' . basename($file)] = (string) file_get_contents($file);
    }

    return $files;
}

test('Уменьшить движение: у каждого @keyframes со сдвигом есть неподвижная замена', function (): void {
    $missing = [];
    foreach (motion_built_css() as $file => $css) {
        foreach (motion_unguarded($css) as $name) {
            $missing[] = $file . ': ' . $name;
        }
    }
    assert_same([], $missing, 'кадры со сдвигом без переопределения в @media (prefers-reduced-motion: reduce)');

    // Сам разбор обязан видеть нарушение, иначе зелёный прогон ничего не значит.
    $probe = '@keyframes a{to{transform:none}}@keyframes b{to{opacity:0}}'
        . '@media (prefers-reduced-motion:reduce){@keyframes c{to{--motion-still:1}}}'
        . '@keyframes c{from{transform:scale(2)}}';
    assert_same(['a', 'c'], motion_unguarded($probe), 'замена до определения не действует');
    $fixed = $probe . '@media (prefers-reduced-motion:reduce){@keyframes a{from{opacity:0}}@keyframes c{to{--motion-still:1}}}';
    assert_same([], motion_unguarded($fixed));
});

test('Уменьшить движение: переходы остаются у прозрачности и цвета, сдвиг — нет', function (): void {
    $bundle = motion_built_css()['public.min.css'];

    assert_false(str_contains($bundle, 'animation-duration:.01ms'), 'общего сжатия всех анимаций больше нет');
    preg_match('~@media \(prefers-reduced-motion:reduce\)\{\*,(?:\*)?::after,(?:\*)?::before\{transition-property:([^!]+)!important~', $bundle, $m);
    assert_true(isset($m[1]), 'общее правило «уменьшить движение» перечисляет свойства переходов');
    $props = array_map('trim', explode(',', $m[1]));
    assert_true(in_array('opacity', $props, true) && in_array('color', $props, true), 'прозрачность и цвет остаются плавными');
    foreach (['transform', 'all', 'translate', 'scale', 'rotate', 'top', 'left', 'width', 'height'] as $moving) {
        assert_false(in_array($moving, $props, true), $moving . ' — это перемещение');
    }

    // Появление при скролле — проявление на месте, а не мгновенный показ.
    // Минификатор сливает одинаковые медиаблоки, поэтому правило ищется в
    // любом блоке «уменьшить движение», а не в своём отдельном.
    assert_same(1, preg_match('~@media \(prefers-reduced-motion:reduce\)\{[^@]*\[data-reveal\]\{transform:none!important\}~', $bundle));
    assert_false(str_contains($bundle, '[data-reveal]{opacity:1!important;transform:none!important;transition:none}'), 'мгновенного показа при системной настройке нет');
    // Явная остановка в панели гасит всё и показывает блоки сразу.
    assert_contains('html[data-a11y-motion=off] *', $bundle);
    assert_contains('html[data-a11y-motion=off] [data-reveal]{opacity:1!important;transform:none!important}', $bundle);
});

test('Уменьшить движение: системная настройка не включает тумблер «остановка анимаций»', function (): void {
    $init = (string) file_get_contents(APP_ROOT . '/public/assets/js/theme-init.js');
    $panel = (string) file_get_contents(APP_ROOT . '/public/assets/js/a11y.js');

    assert_false(str_contains($init, "state.motion = 'off'"), 'theme-init не переводит системную настройку в тумблер');
    assert_false(str_contains($panel, "copy.motion = 'off'"), 'панель не показывает тумблер включённым по системной настройке');
    assert_contains('window.asdrStopMotion = function', $init, 'есть признак полной остановки');

    // Обложка: сдвиг и перелив разведены, «уменьшить движение» снимает только сдвиг.
    $hero = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.min.css');
    assert_contains('opacity calc(var(--hero-duration) * var(--hero-fade))', $hero);
    assert_contains('[data-a11y-motion=off] .hero{--hero-motion:0;--hero-fade:0', $hero);
});
