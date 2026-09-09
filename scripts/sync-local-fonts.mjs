import { mkdir, readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';
import subsetFont from 'subset-font';

/**
 * Подмножество cyrillic-ext у Google — это вся историческая кириллица:
 * глаголица, старославянский, U+A640-A69F. Из неё сайту нужны буквы
 * современных тюркских языков: узбекские Ғғ Ққ Ҳҳ и казахские Әә Ңң Өө Үү Һһ.
 * Без обрезки у Noto Serif этот файл весит 89 КБ и приезжает на каждую
 * страницу узбекской кириллицы.
 *
 * Диапазон U+0490-04FF покрывает всё нужное; U+20B4 (₴) оставлен как
 * единственный небуквенный знак из исходного диапазона.
 */
const EXT_KEEP_RANGE = [0x0490, 0x04ff];
const EXT_KEEP_EXTRA = [0x20b4];
const EXT_UNICODE_RANGE = 'U+0490-04FF, U+20B4';

function extKeepText() {
    let text = '';
    for (let code = EXT_KEEP_RANGE[0]; code <= EXT_KEEP_RANGE[1]; code += 1) {
        text += String.fromCodePoint(code);
    }
    for (const code of EXT_KEEP_EXTRA) {
        text += String.fromCodePoint(code);
    }
    return text;
}

/**
 * Шрифты, входящие в поставку, — ровно два: Noto Sans (текст) и Noto Serif
 * (заголовки). Ими набран сайт по умолчанию, и они же покрывают узбекскую
 * кириллицу одним семейством — у Noto полная поддержка U+0490-04FF.
 *
 * Остальные семейства каталога DesignSettings::GOOGLE_FONTS в репозитории не
 * лежат: их скачивает на сервер App\Core\LocalGoogleFonts при сохранении
 * дизайна. Добавлять сюда новое семейство «на всякий случай» не нужно —
 * каждое стоит ~90 КБ в поставке и ничего не даёт, пока не выбрано.
 *
 * Запрашивается диапазон `wght@400..700`, а не перечисление весов: файлы Noto
 * переменные (в них есть таблицы fvar/gvar), и одна и та же woff2 покрывает всю
 * ось. Прежний запрос `400;600;700` возвращал три @font-face на один и тот же
 * файл — лишние объявления, и любой невыписанный вес (например 500) браузер
 * подделывал синтетическим жирным, хотя настоящий лежал в том же файле.
 */
const fonts = [
    { slug: 'noto-sans', family: 'Noto Sans', query: 'Noto+Sans:wght@400..700', weights: [400, 500, 600, 700] },
    { slug: 'noto-serif', family: 'Noto Serif', query: 'Noto+Serif:wght@400..700', weights: [400, 500, 600, 700] },
];
const subsets = ['cyrillic-ext', 'cyrillic', 'latin'];
const checkOnly = process.argv.includes('--check');
const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36';

const cssPath = (font) => `public/assets/css/${font.slug}.css`;
const fontDir = (font) => `public/assets/fonts/${font.slug}`;

function parseFaces(css) {
    const faces = [];
    const pattern = /\/\*\s*([^*]+?)\s*\*\/\s*(@font-face\s*\{[^}]+\})/g;
    for (const match of css.matchAll(pattern)) {
        const block = match[2];
        const value = (property) => block.match(new RegExp(`${property}\\s*:\\s*([^;]+);`, 'i'))?.[1]?.trim() ?? '';
        faces.push({
            subset: match[1].trim(),
            family: value('font-family').replace(/^['"]|['"]$/g, ''),
            style: value('font-style'),
            weight: value('font-weight'),
            url: block.match(/url\((['"]?)([^)'"]+)\1\)/i)?.[2] ?? '',
            unicodeRange: value('unicode-range'),
        });
    }
    return faces;
}

/** Лицо переменного шрифта объявляет диапазон («400 700»), а не одно число. */
function weightCovered(declared, weight) {
    const parts = declared.trim().split(/\s+/).map(Number);
    if (parts.some(Number.isNaN)) {
        return false;
    }
    const [min, max] = parts.length === 1 ? [parts[0], parts[0]] : [parts[0], parts[1]];
    return weight >= min && weight <= max;
}

function validate(font, faces, source) {
    for (const subset of subsets) {
        for (const weight of font.weights) {
            if (!faces.some((face) => face.subset === subset
                && face.family === font.family
                && face.style === 'normal'
                && weightCovered(face.weight, weight)
                && face.url !== ''
                && face.unicodeRange !== '')) {
                throw new Error(`${font.family} ${weight} has no ${subset} face in ${source}.`);
            }
        }
    }
}

async function fetchOk(url) {
    const response = await fetch(url, { headers: { 'User-Agent': userAgent, Accept: 'text/css,*/*;q=0.1' } });
    if (!response.ok) {
        throw new Error(`${url}: HTTP ${response.status}`);
    }
    return response;
}

async function verify(font) {
    let css;
    try {
        css = await readFile(cssPath(font), 'utf8');
    } catch {
        throw new Error(`${cssPath(font)} is missing. Run npm run sync:fonts.`);
    }
    if (/https?:\/\//i.test(css)) {
        throw new Error(`${cssPath(font)} contains a remote URL.`);
    }
    const faces = parseFaces(css);
    validate(font, faces, cssPath(font));
    const files = new Set(faces.map((face) => face.url));
    const allowed = new RegExp(`^\\.\\./fonts/${font.slug}/[a-z0-9-]+\\.woff2$`);
    for (const relativeUrl of files) {
        if (!allowed.test(relativeUrl)) {
            throw new Error(`Unexpected font URL: ${relativeUrl}`);
        }
        const path = `public/assets/${relativeUrl.replace(/^\.\.\//, '')}`;
        const data = await readFile(path);
        if (data.subarray(0, 4).toString('ascii') !== 'wOF2') {
            throw new Error(`${path} is not WOFF2.`);
        }
    }
    console.log(`Local ${font.family} verified: ${files.size} WOFF2 files.`);
}

async function sync(font) {
    const cssUrl = `https://fonts.googleapis.com/css2?family=${font.query}&display=swap`;
    const remoteCss = await (await fetchOk(cssUrl)).text();
    const faces = parseFaces(remoteCss).filter((face) => subsets.includes(face.subset));
    validate(font, faces, cssUrl);
    await mkdir(fontDir(font), { recursive: true });

    const sourceToFile = new Map();
    for (const face of faces) {
        if (!sourceToFile.has(face.url)) {
            // Один файл на всю ось — «400» в имени врал бы про его содержимое.
            const label = face.weight.includes(' ') ? 'var' : face.weight;
            sourceToFile.set(face.url, `${font.slug}-${label}-${face.subset}.woff2`);
        }
    }
    const extUrls = new Set(faces.filter((face) => face.subset === 'cyrillic-ext').map((face) => face.url));
    await Promise.all([...sourceToFile].map(async ([url, filename]) => {
        const data = Buffer.from(await (await fetchOk(url)).arrayBuffer());
        if (data.subarray(0, 4).toString('ascii') !== 'wOF2') {
            throw new Error(`${url} did not return WOFF2 data.`);
        }
        if (!extUrls.has(url)) {
            await writeFile(`${fontDir(font)}/${filename}`, data);
            return;
        }
        const trimmed = Buffer.from(await subsetFont(data, extKeepText(), { targetFormat: 'woff2' }));
        if (trimmed.subarray(0, 4).toString('ascii') !== 'wOF2' || trimmed.length < 1024) {
            throw new Error(`${url}: subsetting produced an unusable file.`);
        }
        console.log(`  ${filename}: ${data.length} -> ${trimmed.length} B`);
        await writeFile(`${fontDir(font)}/${filename}`, trimmed);
    }));

    // Диапазон обрезанного файла обязан совпадать с тем, что в нём осталось,
    // иначе браузер качает его ради символа, которого там уже нет.
    const blocks = faces.map((face) => {
        const range = face.subset === 'cyrillic-ext' ? EXT_UNICODE_RANGE : face.unicodeRange;
        return `/* ${face.subset} */\n@font-face {\n    font-family: '${font.family}';\n    font-style: normal;\n    font-weight: ${face.weight};\n    font-display: swap;\n    src: url('../fonts/${font.slug}/${sourceToFile.get(face.url)}') format('woff2');\n    unicode-range: ${range};\n}`;
    });
    await writeFile(cssPath(font), `/* Generated by scripts/sync-local-fonts.mjs; self-hosted ${font.family}. */\n\n${blocks.join('\n\n')}\n`);
    await verify(font);
}

for (const font of fonts) {
    if (checkOnly) {
        await verify(font);
    } else {
        await sync(font);
    }
}
