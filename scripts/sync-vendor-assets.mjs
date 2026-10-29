import { readFileSync } from 'node:fs';
import { mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import process from 'node:process';

const files = [
    {
        source: 'node_modules/@melloware/coloris/dist/coloris.min.css',
        target: 'public/assets/vendor/coloris/coloris.min.css',
    },
    {
        source: 'node_modules/@melloware/coloris/dist/umd/coloris.min.js',
        target: 'public/assets/vendor/coloris/coloris.min.js',
    },
    {
        source: 'node_modules/@melloware/coloris/LICENSE',
        target: 'public/assets/vendor/coloris/LICENSE',
    },
    {
        source: 'node_modules/@tabler/icons-sprite/dist/tabler-sprite-nostroke.svg',
        target: 'public/assets/vendor/tabler/tabler-sprite.svg',
    },
    {
        source: 'node_modules/@tabler/icons-sprite/LICENSE',
        target: 'public/assets/vendor/tabler/LICENSE',
    },
    // Сбор Core Web Vitals с реальных посетителей. Считать LCP и особенно INP
    // руками нельзя: у INP своя логика группировки взаимодействий, учёта
    // bfcache и скрытых вкладок. Библиотека маленькая (около 2 КБ сжатой) и
    // отдаётся со своего домена — сторонних запросов у сайта нет.
    {
        source: 'node_modules/web-vitals/dist/web-vitals.iife.js',
        target: 'public/assets/vendor/web-vitals/web-vitals.js',
    },
    {
        source: 'node_modules/web-vitals/LICENSE',
        target: 'public/assets/vendor/web-vitals/LICENSE',
    },
    // Листание PDF книгой (страница /pdf?view=book). Подключается только
    // этой страницей, общий бандл сайта не растёт.
    {
        source: 'node_modules/page-flip/dist/js/page-flip.browser.js',
        target: 'public/assets/vendor/page-flip/page-flip.browser.js',
    },
    {
        source: 'node_modules/page-flip/LICENSE',
        target: 'public/assets/vendor/page-flip/LICENSE',
    },
];

const checkOnly = process.argv.includes('--check');

async function readText(path) {
    return (await readFile(path, 'utf8')).replace(/\r\n?/g, '\n');
}

for (const file of files) {
    // npm-пакеты могут содержать CRLF, а Git нормализует отслеживаемые
    // текстовые файлы в LF. Сравниваем и записываем канонический LF, чтобы
    // check:vendor давал одинаковый результат локально и в GitHub Actions.
    const expected = await readText(file.source);
    if (checkOnly) {
        let current;
        try {
            current = await readText(file.target);
        } catch {
            throw new Error(`${file.target} is missing. Run npm run build:vendor.`);
        }
        if (current !== expected) {
            throw new Error(`${file.target} is stale. Run npm run build:vendor.`);
        }
        continue;
    }

    await mkdir(file.target.slice(0, file.target.lastIndexOf('/')), { recursive: true });
    await writeFile(file.target, expected);
}

const tablerSprite = await readText('node_modules/@tabler/icons-sprite/dist/tabler-sprite-nostroke.svg');
const tablerNames = Array.from(
    tablerSprite.matchAll(/<symbol id="tabler-([^"]+)"/g),
    (match) => match[1]
);
const tablerCatalog = `${JSON.stringify({
    version: 1,
    source: '@tabler/icons-sprite',
    icons: tablerNames,
}, null, 2)}\n`;
const tablerCatalogTarget = 'public/assets/vendor/tabler/tabler-icons.json';

if (checkOnly) {
    let current;
    try {
        current = await readText(tablerCatalogTarget);
    } catch {
        throw new Error(`${tablerCatalogTarget} is missing. Run npm run build:vendor.`);
    }
    if (current !== tablerCatalog) {
        throw new Error(`${tablerCatalogTarget} is stale. Run npm run build:vendor.`);
    }
} else {
    await mkdir(tablerCatalogTarget.slice(0, tablerCatalogTarget.lastIndexOf('/')), { recursive: true });
    await writeFile(tablerCatalogTarget, tablerCatalog);
}

// Индекс смещений символов в спрайте: публичные страницы и админка
// встраивают только те иконки, что реально встретились в HTML, вместо
// загрузки спрайта целиком (2 МБ на каждой странице).
const spriteBuffer = Buffer.from(tablerSprite, 'utf8');
const symbolOpen = Buffer.from('<symbol id="tabler-', 'utf8');
const symbolClose = Buffer.from('</symbol>', 'utf8');
const spriteIndex = [];
let cursor = 0;
while ((cursor = spriteBuffer.indexOf(symbolOpen, cursor)) !== -1) {
    const nameStart = cursor + symbolOpen.length;
    const nameEnd = spriteBuffer.indexOf(0x22, nameStart); // закрывающая кавычка id
    const closeAt = spriteBuffer.indexOf(symbolClose, nameEnd);
    if (nameEnd === -1 || closeAt === -1) {
        break;
    }
    const end = closeAt + symbolClose.length;
    spriteIndex.push([spriteBuffer.slice(nameStart, nameEnd).toString('utf8'), cursor, end - cursor]);
    cursor = end;
}

const indexBody = spriteIndex
    .map(([name, offset, length]) => `    '${name}' => [${offset}, ${length}],`)
    .join('\n');
const spriteIndexFile = `<?php

declare(strict_types=1);

/**
 * Смещения символов в public/assets/vendor/tabler/tabler-sprite.svg:
 * имя иконки => [позиция в байтах, длина].
 *
 * Генерируется командой \`npm run build:vendor\`, руками не правится.
 * Позволяет вырезать нужные <symbol> без разбора двухмегабайтного спрайта.
 */

return [
${indexBody}
];
`;
const spriteIndexTarget = 'app/Core/data/tabler-sprite-index.php';

if (checkOnly) {
    let current;
    try {
        current = await readText(spriteIndexTarget);
    } catch {
        throw new Error(`${spriteIndexTarget} is missing. Run npm run build:vendor.`);
    }
    if (current !== spriteIndexFile) {
        throw new Error(`${spriteIndexTarget} is stale. Run npm run build:vendor.`);
    }
} else {
    await mkdir(spriteIndexTarget.slice(0, spriteIndexTarget.lastIndexOf('/')), { recursive: true });
    await writeFile(spriteIndexTarget, spriteIndexFile);
}

// Просмотрщик PDF для телефонов (страница /pdf). Chrome на Android своего
// просмотрщика не имеет и скачивает файл, поэтому документ рисует PDF.js со
// своего домена. Сборка legacy — она работает и в устаревших WebView
// встроенных браузеров (Telegram, Instagram). Таблицы кодировок (cmaps) и
// стандартные шрифты нужны документам с кириллицей во встроенных CID-шрифтах;
// wasm — декодерам сканов (JBIG2, JPEG 2000) и цветовых профилей. Песочница
// скриптов PDF (quickjs) не берётся: скрипты документов мы не исполняем.
// Файлы двоичные, поэтому сравниваются байтами, без нормализации строк.
const pdfjsRoot = 'node_modules/pdfjs-dist';
const pdfjsTarget = 'public/assets/vendor/pdfjs';
const pdfjsFiles = [
    // Расширение .js, а не .mjs: Apache хостинга .mjs не знает и отдаёт без
    // JavaScript-типа, а модуль и воркер с чужим типом браузер при nosniff
    // не выполняет — просмотрщик падал на живом сайте, а локально работал.
    ['legacy/build/pdf.min.mjs', 'pdf.min.js'],
    ['legacy/build/pdf.worker.min.mjs', 'pdf.worker.min.js'],
    ['LICENSE', 'LICENSE'],
];
for (const dir of ['cmaps', 'iccs', 'standard_fonts', 'wasm']) {
    for (const name of (await readdir(`${pdfjsRoot}/${dir}`)).sort()) {
        if (!name.startsWith('quickjs')) {
            pdfjsFiles.push([`${dir}/${name}`, `${dir}/${name}`]);
        }
    }
}
for (const [source, target] of pdfjsFiles) {
    const expected = await readFile(`${pdfjsRoot}/${source}`);
    const path = `${pdfjsTarget}/${target}`;
    if (checkOnly) {
        let current;
        try {
            current = await readFile(path);
        } catch {
            throw new Error(`${path} is missing. Run npm run build:vendor.`);
        }
        if (!current.equals(expected)) {
            throw new Error(`${path} is stale. Run npm run build:vendor.`);
        }
        continue;
    }
    await mkdir(path.slice(0, path.lastIndexOf('/')), { recursive: true });
    await writeFile(path, expected);
}

// Редактор текста админки. Берётся не весь пакет (в нём исходники, типы и
// три десятка плагинов), а движок, тема, скин, иконки и ровно те плагины,
// что перечислены в editor.js: плагин из списка без файла не грузится, а
// редактор показывает «Failed to load plugin» (так жили codesample, emoticons,
// help, nonbreaking и quickbars на шестой версии — их файлов не было вовсе).
// Русский язык — из tinymce-i18n, набор строк своей мажорной версии (langs8).
// Каталог собирается целиком: файл прежней версии, которого в новой нет,
// удаляется, а проверка считает лишний файл таким же расхождением, как
// устаревший.
const tinymceRoot = 'node_modules/tinymce';
const tinymceTarget = 'public/assets/js/vendor/tinymce';
const tinymcePlugins = [
    'advlist', 'anchor', 'autolink', 'charmap', 'code', 'codesample', 'emoticons',
    'fullscreen', 'help', 'image', 'insertdatetime', 'link', 'lists', 'media',
    'nonbreaking', 'preview', 'quickbars', 'searchreplace', 'table', 'visualblocks',
    'wordcount',
];
const tinymceFiles = [
    [`${tinymceRoot}/tinymce.min.js`, 'tinymce.min.js'],
    [`${tinymceRoot}/license.md`, 'license.md'],
    [`${tinymceRoot}/notices.txt`, 'notices.txt'],
    [`${tinymceRoot}/icons/default/icons.min.js`, 'icons/default/icons.min.js'],
    [`${tinymceRoot}/models/dom/model.min.js`, 'models/dom/model.min.js'],
    [`${tinymceRoot}/themes/silver/theme.min.js`, 'themes/silver/theme.min.js'],
    [`${tinymceRoot}/skins/content/default/content.min.css`, 'skins/content/default/content.min.css'],
    [`${tinymceRoot}/plugins/emoticons/js/emojis.min.js`, 'plugins/emoticons/js/emojis.min.js'],
    [`${tinymceRoot}/plugins/help/js/i18n/keynav/en.js`, 'plugins/help/js/i18n/keynav/en.js'],
    [`${tinymceRoot}/plugins/help/js/i18n/keynav/ru.js`, 'plugins/help/js/i18n/keynav/ru.js'],
    ['node_modules/tinymce-i18n/langs8/ru.js', 'langs/ru.js'],
];
for (const name of ['skin.min.css', 'content.min.css', 'content.inline.min.css', 'skin.shadowdom.min.css']) {
    tinymceFiles.push([`${tinymceRoot}/skins/ui/oxide/${name}`, `skins/ui/oxide/${name}`]);
}
for (const plugin of tinymcePlugins) {
    tinymceFiles.push([`${tinymceRoot}/plugins/${plugin}/plugin.min.js`, `plugins/${plugin}/plugin.min.js`]);
}

async function listFiles(dir, prefix = '') {
    let entries;
    try {
        entries = await readdir(dir, { withFileTypes: true });
    } catch {
        return [];
    }
    const found = [];
    for (const entry of entries) {
        const rel = prefix + entry.name;
        if (entry.isDirectory()) {
            found.push(...await listFiles(`${dir}/${entry.name}`, `${rel}/`));
        } else {
            found.push(rel);
        }
    }
    return found;
}

const tinymceExpected = new Set(tinymceFiles.map(([, target]) => target));
const tinymceExtra = (await listFiles(tinymceTarget)).filter((rel) => !tinymceExpected.has(rel));
if (checkOnly && tinymceExtra.length > 0) {
    throw new Error(`${tinymceTarget}/${tinymceExtra[0]} is not part of TinyMCE ${tinymceVersion()}. Run npm run build:vendor.`);
}
if (!checkOnly) {
    await rm(tinymceTarget, { recursive: true, force: true });
}
for (const [source, target] of tinymceFiles) {
    const expected = await readText(source);
    const path = `${tinymceTarget}/${target}`;
    if (checkOnly) {
        let current;
        try {
            current = await readText(path);
        } catch {
            throw new Error(`${path} is missing. Run npm run build:vendor.`);
        }
        if (current !== expected) {
            throw new Error(`${path} is stale. Run npm run build:vendor.`);
        }
        continue;
    }
    await mkdir(path.slice(0, path.lastIndexOf('/')), { recursive: true });
    await writeFile(path, expected);
}

function tinymceVersion() {
    return JSON.parse(readFileSync(`${tinymceRoot}/package.json`, 'utf8')).version;
}

console.log(`Coloris and Tabler vendor assets ${checkOnly ? 'verified' : 'synced'} (${spriteIndex.length} icons indexed, ${pdfjsFiles.length} PDF.js files, TinyMCE ${tinymceVersion()}: ${tinymceFiles.length} files).`);
