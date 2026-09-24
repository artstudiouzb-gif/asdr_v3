import { readdirSync } from 'node:fs';
import { readFile, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { brotliCompressSync, constants as zlibConstants, gzipSync } from 'node:zlib';
import process from 'node:process';
import CleanCSS from 'clean-css';
import { minify } from 'terser';

const cssSources = [
    'public/assets/css/gov-fonts.css',
    'public/assets/css/frontend.css',
    'public/assets/css/gov-theme.css',
    'public/assets/css/rich-content.css',
    'public/assets/css/a11y.css',
    'public/assets/css/public-layout-polish.css',
    'public/assets/css/public-editorial-pages.css',
    'public/assets/css/public-home.css',
];

const jsSources = [
    'public/assets/js/a11y.js',
    'public/assets/js/frontend.js',
    'public/assets/js/forms.js',
];

// Ассеты, подключаемые не на каждой странице (AssetCollector: JS_MAP, CSS_MAP,
// THEME_PART_MAP), в общий бандл не входят. Раньше они и не минифицировались —
// отдавались исходниками. Здесь они проходят ту же обработку, что и бандлы, и
// попадают в манифест отдельным разделом.
// Список выводится из файловой системы, а не ведётся руками. Пока он был
// ручным, в него не попали одиннадцать блочных стилей, добавленных позже:
// counters (16 КБ), catalog (12.7 КБ), news-feature (11 КБ) и другие уезжали
// браузеру без минификации и без готового сжатия, потому что манифест про них
// не знал, а Asset откатывался на исходник. Забыть строку легко и тихо —
// теперь забывать нечего.
const blockDirs = ['public/assets/css/blocks', 'public/assets/js/blocks'];
const blockSources = [
    ...blockDirs.flatMap((dir) =>
        readdirSync(dir)
            .filter((name) => /\.(css|js)$/.test(name) && !/\.min\.(css|js)$/.test(name))
            .sort()
            .map((name) => `${dir}/${name}`)
    ),
    'public/assets/js/news.js',
];

// Админка. Прежде её CSS и JS уходили браузеру исходниками (447 КБ стилей и
// 291 КБ скриптов), а скрипты к тому же грузились цепочкой: подвал подключал
// загрузчик, тот — admin.js, и каждый следующий слой запрашивался только после
// загрузки предыдущего, то есть пять сетевых кругов подряд. Два CSS-слоя
// добавлял тот же загрузчик, уже после первой отрисовки.
//
// Бандлы собраны по местам подключения, а не одной кучей: каскад админки держится
// на порядке файлов, и между ними стоят вещи, которые в бандл не входят
// (акцентный <style> из AdminBrand сидит между оболочкой и её слоями). Поэтому
// каждый бандл — это ровно те файлы, что и раньше шли подряд, в том же порядке:
//   admin-core   — ядро; его же берут экраны входа, отсюда отдельный файл;
//   admin-shell  — оболочка панели, сразу за ядром;
//   admin-brand  — слои AdminBrand::styleTag() (после акцентного <style>);
//   admin-panel  — слои, которые прежде добавлял загрузчик в конец <head>;
//   admin.min.js — скрипты в том порядке, в каком их исполняла цепочка.
// Бандлы лежат в своём каталоге: маски тестов и бюджетов (`css/admin*.css`)
// считают исходники, и собранная копия рядом посчиталась бы вторично.
const adminBundles = [
    { out: 'public/assets/admin/admin-core.min.css', sources: ['public/assets/css/admin.css'] },
    { out: 'public/assets/admin/admin-shell.min.css', sources: ['public/assets/css/admin-shell-v2.css'] },
    {
        out: 'public/assets/admin/admin-brand.min.css',
        sources: [
            'public/assets/css/admin-notifications.css',
            'public/assets/css/admin-shell-stability.css',
            'public/assets/css/admin-hero-slide-editor.css',
        ],
    },
    {
        out: 'public/assets/admin/admin-panel.min.css',
        sources: [
            'public/assets/css/admin-workflow-fixes.css',
            'public/assets/css/admin-media-unified.css',
            'public/assets/css/admin-slider-settings-layout.css',
        ],
    },
    {
        out: 'public/assets/admin/admin.min.js',
        sources: [
            'public/assets/js/admin.js',
            'public/assets/js/admin-media-bridge.js',
            'public/assets/js/admin-workflow-fixes.js',
            'public/assets/js/admin-slider-settings-layout.js',
            'public/assets/js/admin-gallery-dropzone.js',
            'public/assets/js/admin-media-loadmore.js',
        ],
    },
];

const minifiedName = (path) => path.replace(/\.(css|js)$/, '.min.$1');

const outputs = {
    css: 'public/assets/css/public.min.css',
    js: 'public/assets/js/public.min.js',
    manifest: 'public/assets/asset-manifest.json',
};

// Потолки сжатого размера общих бандлов — тех, что грузит каждый посетитель
// на каждой странице.
//
// Превышение валит `npm run build:assets --check` (то есть CI), но не обычную
// сборку: раньше исключение бросалось в любом режиме, и очередное дополнение
// темы ломало сборку прямо в работе — поэтому порог тогда и сняли. Теперь
// разработчик видит предупреждение и работает дальше, а поймать рост обязан
// CI, где правка видна целиком и понятно, чем платим.
//
// Потолок опускают по мере чистки, а не поднимают под факт. Поднять можно —
// но в том же коммите объяснить, чем рост оправдан. «Подняли, чтобы прошло» —
// не объяснение: смысл порога в том, чтобы разговор о бюджете состоялся до
// слияния, а не задним числом, как это вышло с 419 КБ, набранными полутора
// десятками дизайн-коммитов подряд.
const budgets = {
    cssBrotli: 52 * 1024, // факт на момент установки порога — 49.3 КБ
    jsBrotli: 15 * 1024, // факт — 13.5 КБ (режим чтения и галерея новости живут в news.js)
    // Админка: сумма её бандлов. Порог по факту на момент заведения.
    adminCssBrotli: 46 * 1024, // факт — 45.3 КБ (было 71.8 КБ исходниками)
    adminJsBrotli: 30 * 1024, // факт — 29.1 КБ (было 55.7 КБ цепочкой)
};

// Файлы отдельных блоков грузятся только на страницах, где такой блок есть,
// поэтому их бюджет мягкий (предупреждение в любом режиме): рост здесь платит
// часть посетителей, а не все. Порог ловит аварию вроде случайно попавшего в
// исходники несжатого вендорного файла.
const blockBudgetBrotli = 8 * 1024;

const checkOnly = process.argv.includes('--check');

async function readSources(paths) {
    return Promise.all(paths.map(async (path) => ({
        path,
        // Keep fingerprints and generated artifacts identical on Windows and Linux.
        content: (await readFile(path, 'utf8')).replace(/\r\n?/g, '\n'),
    })));
}

function sizeReport(content) {
    const input = Buffer.from(content);
    return {
        raw: input.length,
        gzip: gzipSync(input, { level: 9 }).length,
        brotli: brotliCompressSync(input, {
            params: {
                [zlibConstants.BROTLI_PARAM_QUALITY]: 11,
            },
        }).length,
    };
}

function sha256(content) {
    return createHash('sha256').update(content).digest('hex');
}

function sourceFingerprint(sources) {
    const hash = createHash('sha256');
    for (const { path, content } of sources) {
        hash.update(path);
        hash.update('\0');
        hash.update(content);
        hash.update('\0');
    }
    return hash.digest('hex');
}

async function buildCss(sources, { safe = false } = {}) {
    const input = sources.map(({ path, content }) => `/* ${path} */\n${content}`).join('\n');
    // Админке — только первый уровень: он сжимает запись, но не трогает
    // порядок правил. Второй уровень сливает разнесённые @media и соседние
    // правила, а CSS панели держится на порядке и на !important — проверить
    // каждое слияние там нечем, а выигрыш — единицы процентов.
    // Сортировку селекторов внутри правила тоже снимаем: на каскад она не
    // влияет, но тогда разбор браузером совпадает с исходниками правило в
    // правило, и это можно проверить, а не принять на веру.
    const level = safe
        ? { 1: { selectorsSortingMethod: 'none' } }
        : {
            1: {},
            2: { restructureRules: false, mergeSemantically: false },
        };
    const result = new CleanCSS({
        // Level 2 доводит оптимизацию до слияния и удаления дублирующихся
        // правил (около 8 KiB на текущем наборе). Реструктуризацию отключаем
        // осознанно: она переупорядочивает правила и при равной специфичности
        // способна изменить победителя каскада — для темы с большим числом
        // переопределений это неприемлемый риск ради нескольких байт.
        level,
        rebase: false,
        returnPromise: false,
    }).minify(input);

    if (result.errors.length > 0) {
        throw new Error(`CSS build failed:\n${result.errors.join('\n')}`);
    }

    return `/*! Generated by npm run build:assets. Do not edit directly. */\n${result.styles}\n`;
}

async function buildJs(sources) {
    const input = Object.fromEntries(sources.map(({ path, content }) => [path, content]));
    const result = await minify(input, {
        compress: {
            passes: 2,
        },
        mangle: true,
        format: {
            comments: false,
        },
    });

    if (!result.code) {
        throw new Error('JavaScript build produced an empty file.');
    }

    return `/*! Generated by npm run build:assets. Do not edit directly. */\n${result.code}\n`;
}

async function verifyOrWrite(path, content) {
    if (!checkOnly) {
        await writeFile(path, content, 'utf8');
        return;
    }

    let current = '';
    try {
        current = (await readFile(path, 'utf8')).replace(/\r\n?/g, '\n');
    } catch {
        throw new Error(`${path} is missing. Run npm run build:assets.`);
    }
    if (current !== content) {
        throw new Error(`${path} is stale. Run npm run build:assets.`);
    }
}

/**
 * Минифицирует один файл блока. CSS проходит тот же CleanCSS, что и бандл;
 * JS — тот же terser. Возвращает запись для манифеста.
 */
async function buildBlockAsset(path) {
    const [{ content }] = await readSources([path]);
    const isCss = path.endsWith('.css');
    const output = minifiedName(path);
    const built = isCss
        ? await buildCss([{ path, content }])
        : await buildJs([{ path, content }]);

    await verifyOrWrite(output, built);

    return [`/${path.replace(/^public\//, '')}`, {
        path: `/${output.replace(/^public\//, '')}`,
        sourceSha256: sha256(content),
        sha256: sha256(built),
        ...sizeReport(built),
    }];
}

const [cssInput, jsInput] = await Promise.all([readSources(cssSources), readSources(jsSources)]);
const [css, js] = await Promise.all([buildCss(cssInput), buildJs(jsInput)]);
const blocks = Object.fromEntries(await Promise.all(blockSources.map(buildBlockAsset)));
const admin = Object.fromEntries(await Promise.all(adminBundles.map(async ({ out, sources }) => {
    const input = await readSources(sources);
    const built = out.endsWith('.css') ? await buildCss(input, { safe: true }) : await buildJs(input);
    await verifyOrWrite(out, built);

    return [`/${out.replace(/^public\//, '')}`, {
        sources: sources.map((path) => `/${path.replace(/^public\//, '')}`),
        sourceSha256: sourceFingerprint(input),
        sha256: sha256(built),
        ...sizeReport(built),
    }];
})));
const adminTotal = (ext) => Object.entries(admin)
    .filter(([path]) => path.endsWith(ext))
    .reduce((sum, [, entry]) => sum + entry.brotli, 0);
const cssSize = sizeReport(css);
const jsSize = sizeReport(js);
const manifest = `${JSON.stringify({
    version: 1,
    generatedBy: 'npm run build:assets',
    css: {
        path: '/assets/css/public.min.css',
        sources: cssSources.map((path) => `/${path.replace(/^public\//, '')}`),
        sourceSha256: sourceFingerprint(cssInput),
        sha256: sha256(css),
        ...cssSize,
    },
    js: {
        path: '/assets/js/public.min.js',
        sources: jsSources.map((path) => `/${path.replace(/^public\//, '')}`),
        sourceSha256: sourceFingerprint(jsInput),
        sha256: sha256(js),
        ...jsSize,
    },
    // Ключ — исходный путь (как он записан в AssetCollector), значение —
    // минифицированный файл. FrontendAssets::blockAsset() подставляет его,
    // когда включена сборка бандлов.
    blocks,
    // Бандлы админки: ключ — собранный файл, подключается напрямую.
    admin,
}, null, 2)}\n`;
await Promise.all([
    verifyOrWrite(outputs.css, css),
    verifyOrWrite(outputs.js, js),
    verifyOrWrite(outputs.manifest, manifest),
]);

const mode = checkOnly ? 'verified' : 'built';
console.log(`Public assets ${mode}:`);
console.log(`  CSS ${cssSize.raw} raw / ${cssSize.gzip} gzip / ${cssSize.brotli} brotli`);
console.log(`  JS  ${jsSize.raw} raw / ${jsSize.gzip} gzip / ${jsSize.brotli} brotli`);
for (const [path, entry] of Object.entries(admin)) {
    console.log(`  админка ${path} -> ${entry.raw} raw / ${entry.gzip} gzip / ${entry.brotli} brotli`);
}
for (const [source, entry] of Object.entries(blocks)) {
    console.log(`  блок ${source} -> ${entry.raw} raw / ${entry.gzip} gzip / ${entry.brotli} brotli`);
}

// Мягкий бюджет блочных файлов: предупреждение в любом режиме.
for (const [source, entry] of Object.entries(blocks)) {
    if (entry.brotli > blockBudgetBrotli) {
        console.warn(
            `  ВНИМАНИЕ: ${source} ${entry.brotli} Б brotli — выше ориентира ${blockBudgetBrotli} Б.`
        );
    }
}

// Жёсткий бюджет общих бандлов. В обычной сборке — предупреждение (файлы уже
// записаны, работа не встаёт), в режиме проверки — ошибка с ненулевым кодом,
// чтобы правка не уехала в main незамеченной.
const overruns = [];
if (cssSize.brotli > budgets.cssBrotli) {
    overruns.push(`CSS ${cssSize.brotli} Б brotli — выше бюджета ${budgets.cssBrotli} Б`);
}
if (jsSize.brotli > budgets.jsBrotli) {
    overruns.push(`JS ${jsSize.brotli} Б brotli — выше бюджета ${budgets.jsBrotli} Б`);
}
if (adminTotal('.css') > budgets.adminCssBrotli) {
    overruns.push(`CSS админки ${adminTotal('.css')} Б brotli — выше бюджета ${budgets.adminCssBrotli} Б`);
}
if (adminTotal('.js') > budgets.adminJsBrotli) {
    overruns.push(`JS админки ${adminTotal('.js')} Б brotli — выше бюджета ${budgets.adminJsBrotli} Б`);
}

if (overruns.length > 0) {
    if (checkOnly) {
        for (const message of overruns) {
            console.error(`  ПРЕВЫШЕН БЮДЖЕТ: ${message}.`);
        }
        console.error(
            '  Уменьшите бандл или поднимите порог в scripts/build-assets.mjs,\n'
            + '  объяснив в том же коммите, чем рост оправдан.'
        );
        process.exitCode = 1;
    } else {
        for (const message of overruns) {
            console.warn(`  ВНИМАНИЕ: ${message}. В CI это уронит проверку.`);
        }
    }
}
