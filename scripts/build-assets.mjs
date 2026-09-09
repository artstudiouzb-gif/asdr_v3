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
    jsBrotli: 15 * 1024, // факт — 13.3 КБ
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

async function buildCss(sources) {
    const input = sources.map(({ path, content }) => `/* ${path} */\n${content}`).join('\n');
    const result = new CleanCSS({
        // Level 2 доводит оптимизацию до слияния и удаления дублирующихся
        // правил (около 8 KiB на текущем наборе). Реструктуризацию отключаем
        // осознанно: она переупорядочивает правила и при равной специфичности
        // способна изменить победителя каскада — для темы с большим числом
        // переопределений это неприемлемый риск ради нескольких байт.
        level: {
            1: {},
            2: { restructureRules: false, mergeSemantically: false },
        },
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
