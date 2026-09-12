import { writeFile } from 'node:fs/promises';
import process from 'node:process';

/**
 * Пересборка каталога Google Fonts для раздела «Дизайн».
 *
 * Каталог в `DesignSettings::GOOGLE_FONTS` — двадцать отобранных семейств; всё
 * остальное, что есть у Google, редактору было недоступно. Этот скрипт собирает
 * полный список и кладёт его в `app/Core/data/google-fonts-index.php`.
 *
 * Индекс лежит файлом в репозитории, а не запрашивается из админки, по той же
 * причине, по которой индекс спрайта иконок собран заранее: список меняется
 * несколько раз в год, а зависеть от доступности чужого сервиса в момент, когда
 * администратор открыл форму, незачем. Файл пересобирается `npm run build:fonts-index`.
 *
 * В индекс попадают только семейства с подмножествами cyrillic-ext + cyrillic +
 * latin: без cyrillic-ext не рисуются узбекские Ғғ Ққ Ҳҳ, и установка такого
 * семейства всё равно отвалилась бы проверкой покрытия в LocalGoogleFonts —
 * то есть выбор в форме был бы ловушкой.
 *
 * Источники — только официальные: список семейств из репозитория google/fonts,
 * подмножества и веса из самого API css2 (он же потом отдаёт файлы серверу).
 */

const FAMILIES_CSV = 'https://raw.githubusercontent.com/google/fonts/main/tags/all/families.csv';
const CSS2 = 'https://fonts.googleapis.com/css2';
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
    + 'AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36';
const REQUIRED_SUBSETS = ['cyrillic-ext', 'cyrillic', 'latin'];
const OUTPUT = new URL('../app/Core/data/google-fonts-index.php', import.meta.url);

/** Наборы весов пробуются от богатого к бедному: первый доступный и берётся. */
const WEIGHT_SETS = [
    ['400', '500', '600', '700'],
    ['400', '600', '700'],
    ['400', '700'],
    ['400'],
];

const BATCH = 24;
const CONCURRENCY = 6;

async function fetchText(url) {
    for (let attempt = 1; attempt <= 3; attempt += 1) {
        try {
            const response = await fetch(url, { headers: { 'User-Agent': USER_AGENT } });
            if (response.status === 400 || response.status === 404) {
                return { status: response.status, body: '' };
            }
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return { status: response.status, body: await response.text() };
        } catch (error) {
            if (attempt === 3) {
                throw error;
            }
            await new Promise((resolve) => setTimeout(resolve, attempt * 800));
        }
    }
    return { status: 0, body: '' };
}

/** Семейства из репозитория google/fonts: первая колонка, строки повторяются. */
async function familyNames() {
    const { body } = await fetchText(FAMILIES_CSV);
    const names = new Set();
    for (const line of body.split('\n')) {
        const name = line.split(',')[0]?.trim();
        if (name) {
            names.add(name);
        }
    }
    return [...names].sort((a, b) => a.localeCompare(b, 'en'));
}

function familyParam(name, weights) {
    const encoded = name.replace(/ /g, '+');
    return weights ? `${encoded}:wght@${weights.join(';')}` : encoded;
}

/**
 * Подмножества каждого семейства из ответа css2: перед каждым @font-face стоит
 * комментарий с именем подмножества, а имя семейства — внутри блока.
 */
function subsetsByFamily(css) {
    const found = new Map();
    const blocks = css.matchAll(/\/\*\s*([^*]+?)\s*\*\/\s*@font-face\s*\{([^}]+)\}/gs);
    for (const [, subset, block] of blocks) {
        const family = block.match(/font-family:\s*'([^']+)'/)?.[1];
        if (!family) {
            continue;
        }
        if (!found.has(family)) {
            found.set(family, new Set());
        }
        found.get(family).add(subset.trim());
    }
    return found;
}

async function mapWithLimit(items, limit, worker) {
    const results = [];
    let index = 0;
    const runners = Array.from({ length: Math.min(limit, items.length) }, async () => {
        while (index < items.length) {
            const current = index;
            index += 1;
            results[current] = await worker(items[current], current);
        }
    });
    await Promise.all(runners);
    return results;
}

/** Семейства, у которых есть все требуемые подмножества. */
async function cyrillicFamilies(names) {
    const batches = [];
    for (let i = 0; i < names.length; i += BATCH) {
        batches.push(names.slice(i, i + BATCH));
    }
    const covered = [];
    let done = 0;
    await mapWithLimit(batches, CONCURRENCY, async (batch) => {
        const query = batch.map((name) => `family=${familyParam(name)}`).join('&');
        const { body } = await fetchText(`${CSS2}?${query}&display=swap`);
        const subsets = subsetsByFamily(body);
        for (const name of batch) {
            const has = subsets.get(name);
            if (has && REQUIRED_SUBSETS.every((subset) => has.has(subset))) {
                covered.push(name);
            }
        }
        done += 1;
        process.stdout.write(`\r  подмножества: пачка ${done}/${batches.length}`);
    });
    process.stdout.write('\n');
    return covered.sort((a, b) => a.localeCompare(b, 'en'));
}

/**
 * Лицо переменного шрифта объявляет диапазон («400 700»), а не одно число:
 * одна woff2 покрывает всю ось. Сравнение строкой такой файл не засчитывало бы.
 */
function weightCovered(declared, weight) {
    const parts = declared.trim().split(/\s+/);
    if (parts.length === 0 || parts.length > 2) {
        return false;
    }
    const min = Number.parseInt(parts[0], 10);
    const max = Number.parseInt(parts[1] ?? parts[0], 10);
    return Number(weight) >= min && Number(weight) <= max;
}

/**
 * Набор весов, который css2 действительно отдаёт для семейства.
 *
 * Спрашивать одним статусом ответа нельзя: недоступный вес css2 молча
 * пропускает и отвечает 200 (запрос `Pacifico:wght@400;700` возвращает один
 * regular). Такой набор записался бы в индекс, а установка потом отвалилась бы
 * проверкой покрытия в LocalGoogleFonts — то есть выбор в форме стал бы
 * ловушкой ровно того рода, ради которой каталог и отбирается заранее.
 */
async function weightsFor(name) {
    for (const weights of WEIGHT_SETS) {
        const { status, body } = await fetchText(`${CSS2}?family=${familyParam(name, weights)}&display=swap`);
        if (status !== 200 || !body.includes('@font-face')) {
            continue;
        }
        const declared = [...body.matchAll(/font-weight:\s*([^;]+);/g)].map((match) => match[1].trim());
        const covered = weights.every((weight) => declared.some((value) => weightCovered(value, weight)));
        if (covered) {
            return weights;
        }
    }
    return null;
}

/**
 * Имя семейства приезжает из чужого файла, а уходит в сгенерированный PHP.
 * Поэтому оно и проверяется набором символов, и экранируется: одной замены
 * кавычки мало — обратный слэш в конце строки съел бы закрывающую кавычку и
 * склеил бы соседние записи (CodeQL ловит это как неполное экранирование).
 */
function safeFamilyName(name) {
    return /^[A-Za-z0-9][A-Za-z0-9 .-]*$/.test(name);
}

function phpSingleQuoted(value) {
    return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function phpDoubleQuoted(value) {
    return `"${value.replace(/\\/g, '\\\\').replace(/["$]/g, (char) => `\\${char}`)}"`;
}

function slugify(name) {
    return name
        .normalize('NFKD')
        .replace(/[^A-Za-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();
}

/**
 * Запасные семейства подбираются по названию: категории css2 не отдаёт, а
 * тащить METADATA.pb каждого семейства ради одного слова незачем — в стеке
 * запасное нужно лишь на время загрузки основного файла.
 */
function fallbackStack(name) {
    if (/mono/i.test(name)) {
        return "ui-monospace, SFMono-Regular, Menlo, monospace";
    }
    if (/serif|slab|antiqua|garamond|playfair|literata/i.test(name)) {
        return 'Georgia, serif';
    }
    return 'system-ui, sans-serif';
}

async function main() {
    console.log('Список семейств…');
    const names = await familyNames();
    console.log(`  всего у Google: ${names.length}`);

    console.log('Отбор семейств с узбекской кириллицей…');
    const cyrillic = await cyrillicFamilies(names);
    console.log(`  с cyrillic-ext + cyrillic + latin: ${cyrillic.length}`);

    console.log('Доступные веса…');
    let done = 0;
    const entries = await mapWithLimit(cyrillic, CONCURRENCY, async (name) => {
        const weights = await weightsFor(name);
        done += 1;
        process.stdout.write(`\r  веса: ${done}/${cyrillic.length}`);
        return weights ? { name, weights } : null;
    });
    process.stdout.write('\n');

    const rows = [];
    for (const entry of entries) {
        if (!entry) {
            continue;
        }
        const slug = slugify(entry.name);
        if (slug === '') {
            continue;
        }
        if (!safeFamilyName(entry.name)) {
            console.warn(`  пропущено небезопасное имя семейства: ${entry.name}`);
            continue;
        }
        rows.push({
            slug,
            label: entry.name,
            stack: `'${entry.name}', ${fallbackStack(entry.name)}`,
            query: familyParam(entry.name, entry.weights),
        });
    }
    rows.sort((a, b) => a.label.localeCompare(b.label, 'en'));

    const php = [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        '/**',
        ' * Каталог Google Fonts с узбекской кириллицей — сгенерированный файл.',
        ' *',
        ' * Не править руками: пересобирается `npm run build:fonts-index`',
        ' * (scripts/build-google-fonts-index.mjs). Формат строки тот же, что у',
        ' * DesignSettings::GOOGLE_FONTS: slug => [подпись, CSS-стек, family для css2].',
        ' *',
        ` * Семейств: ${rows.length}. Отобраны по подмножествам cyrillic-ext + cyrillic + latin.`,
        ' */',
        '',
        'return [',
        ...rows.map((row) => '    '
            + `${phpSingleQuoted(row.slug)} => [`
            + `${phpSingleQuoted(row.label)}, `
            + `${phpDoubleQuoted(row.stack)}, `
            + `${phpSingleQuoted(row.query)}],`),
        '];',
        '',
    ].join('\n');

    await writeFile(OUTPUT, php, 'utf8');
    console.log(`Записано семейств: ${rows.length} -> app/Core/data/google-fonts-index.php`);
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
