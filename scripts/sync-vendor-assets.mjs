import { mkdir, readFile, writeFile } from 'node:fs/promises';
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

console.log(`Coloris and Tabler vendor assets ${checkOnly ? 'verified' : 'synced'} (${spriteIndex.length} icons indexed).`);
