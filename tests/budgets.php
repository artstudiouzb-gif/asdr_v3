<?php

declare(strict_types=1);

/*
 * Реестр бюджетов качества.
 *
 * В проекте несколько величин, которые «могут только уменьшаться»: `!important`
 * в публичном CSS и в CSS админки, классы разметки без правил, жёсткие
 * `border-radius` мимо настройки, находки в эталоне PHPStan, вес общих бандлов.
 * У каждой был свой сторож, и это правильно — падать должно там, где нарушено.
 * Но потолок и способ замера лежали внутри теста, а значит:
 *
 *  - увидеть запас («сколько осталось до потолка») можно было, только уронив
 *    тест: зелёный прогон не печатает ни текущего значения, ни бюджета;
 *  - отчёта по всем бюджетам сразу не существовало вовсе — деградация одного
 *    из них замечалась в момент падения, а не когда её ещё дёшево отменить.
 *
 * Поэтому здесь объявление, а в тестах — проверка. Тот же приём, что со схемой
 * полей блока: величина описана один раз, из описания получаются и потолок, и
 * замер, и строка отчёта. Второй список разъехался бы с первым молча.
 *
 * Потолок опускают по мере уборки. Поднять можно, но в том же коммите объяснив,
 * чем рост оправдан: смысл бюджета в том, чтобы разговор состоялся до слияния.
 *
 * Отчёт по всем бюджетам: `php .claude/skills/quality-budgets/report.php`.
 */

/** @return list<string> публичные таблицы стилей (без собранных и админских) */
function public_css_files(): array
{
    $files = array_merge(
        glob(APP_ROOT . '/public/assets/css/*.css') ?: [],
        glob(APP_ROOT . '/public/assets/css/blocks/*.css') ?: []
    );

    return array_values(array_filter($files, static function (string $path): bool {
        $name = basename($path);

        return !str_contains($name, '.min.') && !str_starts_with($name, 'admin');
    }));
}

/** @return list<string> файлы CSS админки */
function admin_css_files(): array
{
    return glob(APP_ROOT . '/public/assets/css/admin*.css') ?: [];
}

/**
 * Классы разметки админки: класс => файлы, где он встречается.
 *
 * @return array<string,string>
 */
function admin_markup_classes(): array
{
    $used = [];
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app/Views/admin'));
    foreach ($dir as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (!preg_match_all('/class=(["\'])(.*?)\1/s', $src, $m)) {
            continue;
        }
        foreach ($m[2] as $value) {
            // PHP-вставка внутри атрибута оставляет обрубок вида `badge--`.
            $value = (string) preg_replace('/<\?.*?\?>/s', ' ', $value);
            foreach (preg_split('/\s+/', $value) ?: [] as $class) {
                $class = trim($class);
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*[a-zA-Z0-9]$/', $class)) {
                    continue;
                }
                $used[$class] = ($used[$class] ?? '') . ' ' . basename($file->getPathname());
            }
        }
    }

    return $used;
}

/**
 * Классы разметки админки, которых нет ни в CSS, ни в JS панели.
 *
 * @return list<string>
 */
/**
 * Классы, у которых правило живёт только внутри `@media` **по замыслу**.
 *
 * Список нужен затем, что сам приём бывает законным: у класса, который прячет
 * элемент на узком экране или увеличивает цель нажатия, видимое состояние и
 * есть умолчание — базовое правило ему не нужно. Держать такие случаи
 * бюджетом было бы ловушкой: следующий честный класс уронил бы тест, а поднять
 * потолок правила проекта не позволяют.
 *
 * Поэтому объяснённые случаи перечислены здесь с причиной, а бюджет считает
 * только необъяснённые — и его потолок ноль. Цена входа в список — одна строка
 * с обоснованием, а не молчаливое согласие.
 *
 * @var array<string, string>
 */
const ADMIN_MEDIA_ONLY_BY_DESIGN = [
    'admin-tools__quick' => 'скрывает быстрые действия на самых узких экранах; видимое состояние — умолчание',
    'admin-tbtn__label' => 'подпись кнопки шапки прячется на узком экране, сама кнопка оформлена своим классом',
    'admin-tools__lang' => 'переключатель языка перестраивается только на узком экране',
    'menu-node__move' => 'кнопки перестановки добираются до 44px только там, где нужна цель для пальца',
];

/**
 * Классы админки, у которых есть правила ТОЛЬКО внутри `@media`.
 *
 * Такой класс сторож мёртвых классов не ловит: правило у него есть, значит
 * формально он живой. А на деле работает только его половина. Ровно так жил
 * `.form-grid-2col`: в разметке редактора блока он объявлял сетку в две
 * колонки, в CSS у него было единственное правило — внутри
 * `@media (max-width: 860px)`, то есть «схлопни в одну колонку». Самой сетки не
 * существовало нигде, и пары полей градиента и узора никогда в две колонки не
 * вставали, хотя разметка это заявляла.
 *
 * Отличить забытую базу от условного класса по коду нельзя: `display:none` на
 * узком экране — это законный класс, у которого видимое состояние и есть
 * умолчание. Поэтому величина держится бюджетом: сегодняшние случаи разобраны
 * и признаны условными, а **новый** такой класс почти наверняка забытая база —
 * и разговор о нём начнётся с падения теста, а не через полгода.
 *
 * @return list<string>
 */
function admin_media_only_classes(): array
{
    $css = '';
    foreach (admin_css_files() as $file) {
        $css .= (string) file_get_contents($file);
    }

    // Вырезаем блоки @media целиком, считая скобки: вложенные правила внутри
    // них не должны попасть в «базу».
    $base = '';
    $i = 0;
    $n = strlen($css);
    while ($i < $n) {
        $at = strpos($css, '@media', $i);
        if ($at === false) {
            $base .= substr($css, $i);
            break;
        }
        $base .= substr($css, $i, $at - $i);
        $depth = 0;
        $j = strpos($css, '{', $at);
        if ($j === false) {
            break;
        }
        while ($j < $n) {
            if ($css[$j] === '{') {
                $depth++;
            } elseif ($css[$j] === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $j++;
        }
        $i = $j + 1;
    }

    preg_match_all('/\.([A-Za-z0-9_-]+)/', $css, $all);
    preg_match_all('/\.([A-Za-z0-9_-]+)/', $base, $baseMatch);
    $inBase = array_flip($baseMatch[1]);

    // Считаем только те, что и правда стоят в разметке: класс, которого нигде
    // нет, — это забота бюджета мёртвых классов, а не этого.
    $used = admin_markup_classes();
    $out = [];
    foreach (array_unique($all[1]) as $class) {
        if (!isset($inBase[$class]) && isset($used[$class])) {
            $out[] = $class;
        }
    }
    sort($out);

    return $out;
}

/**
 * Те же классы, у которых нет объяснения в ADMIN_MEDIA_ONLY_BY_DESIGN.
 *
 * Именно их и считает бюджет: у необъяснённого случая куда больше шансов
 * оказаться забытой базой, чем осознанным решением.
 *
 * @return list<string>
 */
function admin_media_only_unexplained(): array
{
    return array_values(array_filter(
        admin_media_only_classes(),
        static fn (string $class): bool => !isset(ADMIN_MEDIA_ONLY_BY_DESIGN[$class])
    ));
}

function admin_orphan_classes(): array
{
    $css = '';
    foreach (admin_css_files() as $file) {
        $css .= (string) file_get_contents($file);
    }
    $js = '';
    foreach (glob(APP_ROOT . '/public/assets/js/admin*.js') ?: [] as $file) {
        $js .= (string) file_get_contents($file);
    }

    /*
     * Скрипты живут не только в `public/assets/js`: часть админских экранов
     * несёт свой `<script>` прямо во вьюхе, и класс бывает хуком именно там
     * (`querySelectorAll('.doc-item-row')`, `data-remove-closest=".doc-item-row"`).
     * Пока проверка смотрела только в собранные файлы, такой класс числился
     * мёртвым — то есть сторож разрешал удалить рабочий хук, а это хуже, чем
     * лишний класс в разметке.
     *
     * Из самих вьюх вырезаем содержимое `class="…"`: там имя класса встречается
     * по определению, и без этого мёртвым не считался бы никто.
     */
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app/Views/admin'));
    foreach ($dir as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        $js .= (string) preg_replace('/class=(["\']).*?\1/s', ' ', $src);
    }

    $orphans = [];
    foreach (admin_markup_classes() as $class => $files) {
        // Имя класса целиком: `.admin-grid` не должен считаться найденным
        // из-за `.admin-grid-auto`.
        if (preg_match('/\.' . preg_quote($class, '/') . '(?![a-zA-Z0-9_-])/', $css)) {
            continue;
        }
        if (str_contains($js, $class)) {
            continue;
        }
        $orphans[] = $class;
    }

    sort($orphans);

    return $orphans;
}

/**
 * Объявления border-radius в публичном CSS.
 *
 * @return list<array{file: string, selector: string, value: string}>
 */
function public_radius_rules(): array
{
    $root = APP_ROOT . '/public/assets/css';
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.css')
            || str_contains($path, '.min.')
            || str_contains($path, 'admin')
            || str_contains($path, 'vendor')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    $rules = [];
    foreach ($files as $path) {
        $css = (string) file_get_contents($path);
        preg_match_all('/([^{}]+)\{([^}]*)\}/s', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $rule) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $rule[1]));
            if ($selector === '' || str_starts_with($selector, '@')) {
                continue;
            }
            preg_match_all('/border(?:-[a-z-]+)?-radius\s*:\s*([^;!}]+)/', $rule[2], $found);
            foreach ($found[1] as $value) {
                $rules[] = ['file' => basename($path), 'selector' => $selector, 'value' => trim($value)];
            }
        }
    }

    return $rules;
}

/**
 * Жёсткие скругления: те, которыми настройка «Дизайна» не управляет.
 * Круги, пилюли и нули не в счёт — это форма элемента, а не оформление.
 *
 * @return list<array{file: string, selector: string, value: string}>
 */
function public_hard_radius_rules(): array
{
    return array_values(array_filter(public_radius_rules(), static function (array $rule): bool {
        $value = $rule['value'];

        return !str_contains($value, 'var(')
            && preg_match('/^(0|0px|50%|100%|999px|9999px|inherit)$/', $value) !== 1;
    }));
}

/**
 * Вызовы `json_encode()` вместе с их выражением целиком.
 *
 * Разбор именно по выражению, а не по строке: после правки флаг и сам вызов
 * часто оказываются на разных строках, и построчная проверка объявила бы
 * защищённый вызов незащищённым — то есть требовала бы «починить» уже
 * починенное.
 *
 * @return list<array{file: string, line: int, expr: string, cast: bool}>
 */
function json_encode_call_sites(): array
{
    $sites = [];

    foreach ([APP_ROOT . '/app', APP_ROOT . '/templates'] as $root) {
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($dir as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/(\(string\)\s*)?json_encode\s*\(/', $src, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($m[0] as $i => $match) {
                $open = strpos($src, '(', (int) $match[1] + strlen($m[0][$i][0]) - 1);
                if ($open === false) {
                    continue;
                }

                $depth = 0;
                $len = strlen($src);
                $close = $open;
                for ($j = $open; $j < $len; $j++) {
                    if ($src[$j] === '(') {
                        $depth++;
                    } elseif ($src[$j] === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $close = $j;
                            break;
                        }
                    }
                }

                $sites[] = [
                    'file' => str_replace(APP_ROOT . '/', '', $file->getPathname()),
                    'line' => substr_count($src, "\n", 0, (int) $match[1]) + 1,
                    'expr' => substr($src, (int) $match[1], $close - (int) $match[1] + 1),
                    'cast' => $m[1][$i][1] !== -1,
                ];
            }
        }
    }

    return $sites;
}

/** Вызов защищён, если отказ либо назван, либо заменён. */
function json_encode_guarded(string $expr): bool
{
    return str_contains($expr, 'JSON_THROW_ON_ERROR')
        || str_contains($expr, 'JSON_INVALID_UTF8_SUBSTITUTE');
}

/** Сколько раз встречается подстрока во всех перечисленных файлах. */
function quality_count_in_files(array $files, string $needle): int
{
    $total = 0;
    foreach ($files as $path) {
        $total += substr_count((string) file_get_contents($path), $needle);
    }

    return $total;
}

/** Пять самых частых файлов по числу вхождений — для объяснения при падении. */
function quality_top_files(array $files, string $needle): string
{
    $perFile = [];
    foreach ($files as $path) {
        $n = substr_count((string) file_get_contents($path), $needle);
        if ($n > 0) {
            $perFile[basename($path)] = $n;
        }
    }
    arsort($perFile);

    $top = [];
    foreach (array_slice($perFile, 0, 5, true) as $name => $n) {
        $top[] = $name . '=' . $n;
    }

    return implode(', ', $top);
}

/**
 * Потолок веса бандла из scripts/build-assets.mjs.
 *
 * Числа живут там: их читает сборка, она же роняет `npm run check:assets`.
 * Скопировать их сюда значило бы завести второй потолок, который разойдётся
 * с первым при первой правке, — поэтому значение вычитывается из файла, а не
 * дублируется. Не нашли объявление — это отказ, а не ноль: молчаливый ноль
 * превратил бы бюджет в вечно пройденный.
 */
function quality_asset_budget(string $key): int
{
    $path = APP_ROOT . '/scripts/build-assets.mjs';
    $source = (string) @file_get_contents($path);
    if (!preg_match('/' . preg_quote($key, '/') . ':\s*(\d+)\s*\*\s*(\d+)/', $source, $m)) {
        throw new RuntimeException('в build-assets.mjs не найден бюджет ' . $key);
    }

    return (int) $m[1] * (int) $m[2];
}

/**
 * Размер файла после brotli качества 11 — тем же способом, что считает сборка.
 *
 * Brotli в PHP нет, поэтому считает node: он и так нужен для `build:assets`.
 * Node недоступен — величина не измерена (null), и отчёт скажет об этом прямо,
 * а не покажет ноль как «уложились».
 */
function quality_brotli_size(string $path): ?int
{
    if (!is_file($path)) {
        return null;
    }

    $script = 'const z=require("zlib"),fs=require("fs");'
        . 'process.stdout.write(String(z.brotliCompressSync(fs.readFileSync(process.argv[1]),'
        . '{params:{[z.constants.BROTLI_PARAM_QUALITY]:11}}).length))';

    $command = 'node -e ' . escapeshellarg($script) . ' ' . escapeshellarg($path) . ' 2>/dev/null';
    $output = @shell_exec($command);
    if (!is_string($output) || !preg_match('/^\d+$/', trim($output))) {
        return null;
    }

    return (int) trim($output);
}

/*
 * Сторож дрейфа дизайн-системы: в публичной теме накопились 73 разных статичных
 * размера шрифта, 163 тени и 606 !important. Значения вроде 0.74/0.75/0.76rem —
 * не решения, а следы ручной подгонки отдельных компонентов: когда общего
 * словаря нет, единственный способ победить соседнее правило — поднять
 * приоритет, и каждая правка тянет за собой ещё один !important.
 *
 * Привести всё разом не требуется — фиксируется потолок. Порог опускают ПО
 * ФАКТУ чистки: перевёл компонент на токены из :root (--step-*, --space-*,
 * --shadow-*) — уменьшил число здесь. Поднимать, чтобы «прошло», нельзя: это
 * ровно тот путь, которым 33 брейкпоинта когда-то и появились.
 */
const DESIGN_SCALE_CEILINGS = [
    // 73 -> 51 -> 12. Сначала схлопнуты соседние размеры, отличавшиеся меньше
    // чем на 2 %, затем вся типографика переведена на шкалу --step-*
    // (см. план, раздел 3.1). Оставшиеся 12 — значения в px и em: размеры
    // элементов интерфейса, а не текста. Потолок опущен по факту чистки —
    // так он и должен двигаться.
    // 13-е значение — 1.12em у .tx-mark в режиме «Рукописный шрифт»: рукописное
    // семейство оптически мельче наборного и выравнивается по РОДИТЕЛЬСКОМУ
    // заголовку, каким бы тот ни был. Ступень шкалы здесь не подходит — она
    // абсолютная и оторвала бы выделенное слово от строки, в которой стоит.
    'font-size' => 13,
    // 163 -> 161 -> 156: последнее число снято отчётом по бюджетам, потолок
    // опущен под факт (запас — это разрешение деградировать).
    'box-shadow' => 156,
    // 36 -> 32, тем же замером.
    'border-radius' => 32,
    // 610 -> 384. Из темы снято 194 приоритета: каждый снимали и сверяли
    // вычисленные стили всех элементов на 9 страницах в двух ширинах и двух
    // темах — возвращали те, без которых что-то менялось (см. план, 3.1).
    //
    // Сначала снято было 335, и CI поймал ошибку: печатная вёрстка. Снимок
    // берёт страницу в покое, в экранной медиасреде, без пользовательских
    // предпочтений и без классов, которые вешает JS, — всё, что живёт за
    // пределами этого состояния, он не проверяет вовсе. Поэтому приоритеты
    // возвращены целиком в @media print, prefers-reduced-motion,
    // prefers-contrast, forced-colors, (hover: none), (pointer: coarse) и у
    // селекторов состояний (.is-*, [aria-*], [data-a11y], .no-transition).
    //
    // Оставшиеся 384 — законные случаи: настройка из админки обязана перебить
    // тему, состояние обязано перебить покой, а сброс движения объявлен
    // селектором «*» и иначе проигрывает компонентам по специфичности.
    // Порог опускают по факту чистки; «поднять, чтобы прошло» — не повод.
    // 384 -> 320: чистка ушла дальше потолка, а потолок за ней не опустили —
    // отчёт по бюджетам это и показал.
    '!important' => 320,
];

/** Публичный CSS целиком: тема, её вынесенные части и слои поверх неё. */
function public_design_css(): string
{
    static $css = null;
    if ($css !== null) {
        return $css;
    }

    $files = [
        'frontend.css',
        'gov-theme.css',
        'public-layout-polish.css',
        'public-editorial-pages.css',
        'rich-content.css',
        'a11y.css',
    ];
    $paths = array_map(static fn (string $f): string => APP_ROOT . '/public/assets/css/' . $f, $files);

    // Части, вынесенные из темы (THEME_PART_MAP), — это та же дизайн-система,
    // просто в отдельных файлах: их считаем. А самостоятельные ассеты блоков
    // из CSS_MAP — нет: у такого блока свой визуальный язык, к шкалам темы он
    // отношения не имеет. Сейчас CSS_MAP пуст, но фильтр оставлен, чтобы
    // метрика не сломалась, когда туда что-то добавят.
    $collector = (string) file_get_contents(APP_ROOT . '/app/Core/AssetCollector.php');
    $standalone = [];
    if (preg_match('/const CSS_MAP = \[(.*?)\];/s', $collector, $m) === 1) {
        preg_match_all("#'(/assets/css/[^']+)'#", $m[1], $found);
        $standalone = array_map(static fn (string $p): string => basename($p), $found[1]);
    }

    foreach (glob(APP_ROOT . '/public/assets/css/blocks/*.css') ?: [] as $file) {
        if (str_ends_with($file, '.min.css') || in_array(basename($file), $standalone, true)) {
            continue;
        }
        $paths[] = $file;
    }

    $css = '';
    foreach ($paths as $path) {
        $css .= (string) @file_get_contents($path) . "\n";
    }

    return $css;
}

/** @return list<string> уникальные статичные значения свойства */
function unique_static_values(string $css, string $property): array
{
    preg_match_all('/' . preg_quote($property, '/') . ':\s*([^;}]+)/', $css, $m);
    $values = [];
    foreach ($m[1] as $value) {
        $value = trim($value);
        // var() и none считать бессмысленно: это уже ссылка на токен.
        if ($value === '' || $value === 'none' || str_starts_with($value, 'var(')) {
            continue;
        }
        // «.92rem» и «0.92rem» — одно значение, ведущий ноль в CSS не
        // обязателен. Считаем по нормализованной записи, иначе метрика
        // ловила бы разнобой в оформлении, а не реальный дрейф шкалы.
        $value = (string) preg_replace('/(?<![\w.])\.(?=\d)/', '0.', $value);
        $value = (string) preg_replace('/(?<![\w.])(0\.\d*?)0+(?=[a-z%\s,)]|$)/', '$1', $value);
        if ($property === 'font-size' && preg_match('/^-?\d*\.?\d+(rem|px|em)$/', $value) !== 1) {
            // clamp()/calc() — плавные размеры, к пересчёту на ступени не относятся.
            continue;
        }
        $values[$value] = true;
    }

    return array_keys($values);
}

/**
 * Все бюджеты проекта.
 *
 * `ceiling` — потолок, `measure` — замер, `guard` — где стоит сторож (чтобы
 * из отчёта было видно, что именно упадёт). `detail` замера объясняет число:
 * при падении по нему сразу видно, куда смотреть.
 *
 * @return array<string, array{
 *     title: string,
 *     unit: string,
 *     guard: string,
 *     why: string,
 *     ceiling: callable(): int,
 *     measure: callable(): array{value: ?int, detail: string}
 * }>
 */
function quality_budgets(): array
{
    return [
        'public_important' => [
            'title' => '!important в публичном CSS',
            'unit' => 'шт',
            'guard' => 'tests/cases/262_css_hygiene_test.php',
            'why' => 'каждый !important — правка, не выигравшая по специфичности; '
                . 'следующая поверх него становится непредсказуемой',
            // 449 → 367 после уборки → 366 замером отчёта → 364: сворачивание
            // «Коллажа» в столбец перестало спорить с scoped CSS флагом
            // приоритета — полотно просто перестаёт быть сеткой. Потолок
            // опускается под факт в том же коммите, что и уборка.
            'ceiling' => static fn (): int => 364,
            'measure' => static function (): array {
                $files = public_css_files();

                return [
                    'value' => quality_count_in_files($files, '!important'),
                    'detail' => quality_top_files($files, '!important'),
                ];
            },
        ],
        'admin_important' => [
            'title' => '!important в CSS админки',
            'unit' => 'шт',
            'guard' => 'tests/cases/279_admin_typography_scale_test.php',
            'why' => 'слой «Enterprise Scale» перебивает компоненты; разбирается семьями: '
                . 'правило переносится в компонент, потом снимается !important, '
                . 'а срез вычисленных стилей доказывает, что вид не поехал',
            'ceiling' => static fn (): int => 268,
            'measure' => static function (): array {
                $files = admin_css_files();

                return [
                    'value' => quality_count_in_files($files, '!important'),
                    'detail' => quality_top_files($files, '!important'),
                ];
            },
        ],
        'admin_dead_classes' => [
            'title' => 'классы админки без правил в CSS',
            'unit' => 'шт',
            'guard' => 'tests/cases/278_admin_dead_classes_test.php',
            'why' => 'класс без правила ничего не ломает и потому живёт годами: '
                . '.btn--success рисовал обычную серую кнопку',
            // 52 -> 15 -> 5. Оставшиеся пять — блоки BEM, у которых оформлены
            // элементы (`.hb-zone__…`), а сам блок правила не имеет: имя
            // держит структуру разметки, а не вид.
            'ceiling' => static fn (): int => 5,
            'measure' => static function (): array {
                $orphans = admin_orphan_classes();

                return [
                    'value' => count($orphans),
                    'detail' => implode(', ', array_slice($orphans, 0, 12)),
                ];
            },
        ],
        'admin_media_only_classes' => [
            'title' => 'классы админки только внутри @media, без базового правила',
            'unit' => 'шт',
            'guard' => 'tests/cases/367_admin_media_only_classes_test.php',
            'why' => 'сторож мёртвых классов такой класс не ловит — правило у него есть, '
                . 'работает только половина: так .form-grid-2col объявлял сетку в две колонки, '
                . 'а в CSS у него было единственное правило «схлопни в одну»',
            // Потолок ноль, но считаются только НЕобъяснённые: приём бывает
            // законным (скрыть на узком экране, увеличить цель нажатия), и
            // держать законные случаи храповиком значило бы уронить тест на
            // следующем честном классе, не дав его добавить. Объяснённые
            // перечислены в ADMIN_MEDIA_ONLY_BY_DESIGN — цена входа туда одна
            // строка с причиной.
            'ceiling' => static fn (): int => 0,
            'measure' => static function (): array {
                $classes = admin_media_only_unexplained();

                return [
                    'value' => count($classes),
                    'detail' => implode(', ', $classes),
                ];
            },
        ],
        'public_hard_radius' => [
            'title' => 'жёсткие border-radius в публичном CSS',
            'unit' => 'шт',
            'guard' => 'tests/cases/309_radius_setting_reach_test.php',
            'why' => 'правило, где радиус написан числом, не слушает настройку '
                . '«Скругление углов» — редактор двигает ползунок, а половина страницы не меняется',
            'ceiling' => static fn (): int => 90,
            'measure' => static function (): array {
                $hard = public_hard_radius_rules();
                $sample = [];
                foreach (array_slice($hard, 0, 6) as $rule) {
                    $sample[] = $rule['file'] . ' ' . $rule['selector'];
                }

                return ['value' => count($hard), 'detail' => implode('; ', $sample)];
            },
        ],
        'phpstan_baseline' => [
            'title' => 'находки в эталоне PHPStan',
            'unit' => 'шт',
            'guard' => 'tests/cases/293_phpstan_baseline_budget_test.php',
            'why' => 'новый код проверяется целиком, старый долг посчитан и виден; '
                . 'дописать находку в эталон вместо починки нельзя',
            // 724 -> 578 -> 577 -> 570 -> 565: каждая закрытая находка оказывалась
            // настоящим отказом под strict_types (см. коммиты).
            'ceiling' => static fn (): int => 565,
            'measure' => static function (): array {
                $baseline = APP_ROOT . '/phpstan-baseline.neon';
                if (!is_file($baseline)) {
                    return ['value' => null, 'detail' => 'эталон не найден'];
                }

                $text = str_replace("\r\n", "\n", (string) file_get_contents($baseline));
                $found = 0;
                $entries = 0;
                if (preg_match_all('/^\s*count:\s*(\d+)$/m', $text, $m) > 0) {
                    foreach ($m[1] as $n) {
                        $found += (int) $n;
                        $entries++;
                    }
                }

                return ['value' => $found, 'detail' => $entries . ' записей эталона'];
            },
        ],
        'design_font_sizes' => [
            'title' => 'разных статичных font-size в теме',
            'unit' => 'шт',
            'guard' => 'tests/cases/236_design_scale_drift_test.php',
            'why' => 'размеры берутся только из шкалы --step-* в :root; '
                . 'подобранный заново «ещё один 0.92rem» — след ручной подгонки',
            'ceiling' => static fn (): int => DESIGN_SCALE_CEILINGS['font-size'],
            'measure' => static function (): array {
                $values = unique_static_values(public_design_css(), 'font-size');
                sort($values);

                return ['value' => count($values), 'detail' => implode(', ', array_slice($values, 0, 12))];
            },
        ],
        'design_shadows' => [
            'title' => 'разных box-shadow в теме',
            'unit' => 'шт',
            'guard' => 'tests/cases/236_design_scale_drift_test.php',
            'why' => 'ступени тени — --shadow-1..4; форму задаёт «Стиль карточек», '
                . 'цвет и силу — настройки, а не константа в правиле',
            'ceiling' => static fn (): int => DESIGN_SCALE_CEILINGS['box-shadow'],
            'measure' => static function (): array {
                $values = unique_static_values(public_design_css(), 'box-shadow');

                return ['value' => count($values), 'detail' => count($values) . ' уникальных значений'];
            },
        ],
        'design_radii' => [
            'title' => 'разных border-radius в теме',
            'unit' => 'шт',
            'guard' => 'tests/cases/236_design_scale_drift_test.php',
            'why' => 'скругление задаёт админка (--radius, --btn-radius), '
                . 'для «таблетки» есть --radius-pill',
            'ceiling' => static fn (): int => DESIGN_SCALE_CEILINGS['border-radius'],
            'measure' => static function (): array {
                $values = unique_static_values(public_design_css(), 'border-radius');
                sort($values);

                return ['value' => count($values), 'detail' => implode(', ', array_slice($values, 0, 12))];
            },
        ],
        'design_important' => [
            'title' => '!important в дизайн-системе темы',
            'unit' => 'шт',
            'guard' => 'tests/cases/236_design_scale_drift_test.php',
            'why' => 'из темы уже снято 194 приоритета со сверкой вычисленных стилей; '
                . 'оставшиеся законны — настройка обязана перебить тему, состояние — покой',
            'ceiling' => static fn (): int => DESIGN_SCALE_CEILINGS['!important'],
            'measure' => static function (): array {
                $count = preg_match_all('/!important/', public_design_css());

                return ['value' => (int) $count, 'detail' => 'считается по public_design_css()'];
            },
        ],
        'blocks_off_schema' => [
            'title' => 'типы блоков вне схемы полей',
            'unit' => 'шт',
            'guard' => 'tests/cases/354_readme_facts_test.php',
            'why' => 'у типа без схемы настройка объявлена в четырёх местах — реестр, форма, '
                . 'collectData(), шаблон — и списки значений расходятся молча',
            'ceiling' => static fn (): int => 4,
            'measure' => static function (): array {
                $off = array_values(array_diff(
                    array_keys(\App\Core\BlockTypeRegistry::BASE_DEFAULTS),
                    array_keys(\App\Core\BlockData\BlockFieldSchema::all())
                ));

                return ['value' => count($off), 'detail' => implode(', ', $off)];
            },
        ],
        'json_encode_unguarded' => [
            'title' => 'json_encode с приведением и без флага отказа',
            'unit' => 'шт',
            'guard' => 'tests/cases/356_json_encode_failure_test.php',
            'why' => '`(string) false` — это пустая строка: отказ кодирования исчезает '
                . 'бесследно, и данные молча подменяются пустотой',
            'ceiling' => static fn (): int => 9,
            'measure' => static function (): array {
                $sites = [];
                foreach (json_encode_call_sites() as $site) {
                    if ($site['cast'] && !json_encode_guarded($site['expr'])) {
                        $sites[] = $site['file'] . ':' . $site['line'];
                    }
                }
                sort($sites);

                return ['value' => count($sites), 'detail' => implode(', ', array_slice($sites, 0, 6))];
            },
        ],
        'bundle_css' => [
            'title' => 'вес public.min.css (brotli)',
            'unit' => 'Б',
            'guard' => 'npm run check:assets',
            'why' => 'общий бандл грузит каждый посетитель; 419 КБ однажды набрались '
                . 'полутора десятками дизайн-коммитов подряд',
            'ceiling' => static fn (): int => quality_asset_budget('cssBrotli'),
            'measure' => static function (): array {
                $path = APP_ROOT . '/public/assets/css/public.min.css';

                return [
                    'value' => quality_brotli_size($path),
                    'detail' => 'по собранному бандлу; свежесть — npm run check:assets',
                ];
            },
        ],
        'bundle_js' => [
            'title' => 'вес public.min.js (brotli)',
            'unit' => 'Б',
            'guard' => 'npm run check:assets',
            'why' => 'то же, что у CSS: платит каждый посетитель',
            'ceiling' => static fn (): int => quality_asset_budget('jsBrotli'),
            'measure' => static function (): array {
                $path = APP_ROOT . '/public/assets/js/public.min.js';

                return [
                    'value' => quality_brotli_size($path),
                    'detail' => 'по собранному бандлу; свежесть — npm run check:assets',
                ];
            },
        ],
    ];
}

/**
 * Один бюджет вместе с замером: потолок, текущее значение и объяснение.
 *
 * @return array{title: string, unit: string, guard: string, why: string,
 *     ceiling: int, value: ?int, detail: string}
 */
function quality_budget(string $id): array
{
    $all = quality_budgets();
    if (!isset($all[$id])) {
        throw new RuntimeException('неизвестный бюджет: ' . $id);
    }

    $entry = $all[$id];
    $measured = ($entry['measure'])();

    return [
        'title' => $entry['title'],
        'unit' => $entry['unit'],
        'guard' => $entry['guard'],
        'why' => $entry['why'],
        'ceiling' => ($entry['ceiling'])(),
        'value' => $measured['value'],
        'detail' => $measured['detail'],
    ];
}
