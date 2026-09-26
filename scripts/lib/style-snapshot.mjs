/*
 * Общая часть срезов вычисленных стилей: обход дерева, гашение переходов и
 * сравнение двух срезов. Её берут оба среза — админки
 * (scripts/admin-style-snapshot.mjs) и публичной части
 * (scripts/public-style-snapshot.mjs).
 *
 * Одна копия, а не две: срез — это доказательство «вид не поехал», и если
 * обход или сравнение разъедутся между копиями, одна из них начнёт молча
 * пропускать то, что ловит вторая.
 */
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

export const ROOT = path.resolve(import.meta.dirname, '../..');

/**
 * Браузер. Путь к Chromium переопределяется той же переменной, что в
 * playwright.config.js: в контейнере разработки версия браузера бывает не той,
 * которую ждёт установленный Playwright.
 */
export function launchBrowser() {
    const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH;
    return chromium.launch(executablePath ? { executablePath } : {});
}

/** Свойства, которые записывает срез у каждого узла. */
export const PROPS = [
    'display', 'position', 'boxSizing', 'width', 'height', 'minHeight', 'maxWidth',
    'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
    'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform',
    'color', 'backgroundColor', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth',
    'borderLeftWidth', 'borderTopColor', 'borderBottomColor', 'borderRadius',
    'flexDirection', 'alignItems', 'justifyContent', 'gap', 'gridTemplateColumns',
    'opacity', 'overflowX', 'overflowY', 'textAlign', 'whiteSpace', 'boxShadow', 'accentColor',
];

/**
 * Гасит переходы и анимации. Одного тега стилей мало: слой переопределений
 * панели объявляет свои transition через !important и побеждает тег по
 * специфичности — срез ловил цвет посреди смены темы. Инлайн-стиль с
 * !important сильнее любого правила таблицы, поэтому проходим по узлам; тег
 * остаётся ради псевдоэлементов, до которых инлайном не дотянуться.
 */
export async function freeze(page) {
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important}' });
    await page.evaluate(() => {
        for (const el of document.querySelectorAll('*')) {
            // У узлов вне HTML и SVG (MathML внутри редактора) инлайн-стиля нет.
            if (!el.style || typeof el.style.setProperty !== 'function') { continue; }
            el.style.setProperty('transition', 'none', 'important');
            el.style.setProperty('animation', 'none', 'important');
        }
    });
}

/**
 * Снимает всё дерево <body>. Ключ узла — путь по индексам детей плюс тег и
 * первые три класса: путь держит порядок, а классы делают diff читаемым.
 */
export async function captureTree(page, props = PROPS) {
    return page.evaluate((list) => {
        const result = {};
        const walk = (el, keyPath) => {
            const computed = getComputedStyle(el);
            const record = {};
            for (const prop of list) { record[prop] = computed[prop]; }
            const cls = typeof el.className === 'string'
                ? el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
            result[keyPath + '|' + el.tagName.toLowerCase() + (cls ? '.' + cls : '')] = record;
            [...el.children].forEach((child, i) => walk(child, keyPath + '>' + i));
        };
        walk(document.body, '0');
        return result;
    }, props);
}

export function writeSnapshot(dir, file, data) {
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, file), JSON.stringify(data));
    return Object.keys(data).length;
}

/**
 * Сравнивает два среза: группирует одинаковые изменения (узел, свойство,
 * было → стало) и печатает самые частые. Пропавшие узлы считаются отдельно —
 * изменившаяся разметка это не изменившийся стиль.
 */
export function diffSnapshots(store, a, b, limit = 60) {
    const dirA = path.join(store, a);
    const dirB = path.join(store, b);
    const groups = new Map();
    let changed = 0;
    let seen = 0;
    let missing = 0;

    for (const file of fs.readdirSync(dirA)) {
        if (!file.endsWith('.json')) { continue; }
        const before = JSON.parse(fs.readFileSync(path.join(dirA, file), 'utf8'));
        const afterPath = path.join(dirB, file);
        if (!fs.existsSync(afterPath)) { console.log('нет файла', file); continue; }
        const after = JSON.parse(fs.readFileSync(afterPath, 'utf8'));
        for (const [key, record] of Object.entries(before)) {
            seen++;
            const other = after[key];
            if (!other) { missing++; continue; }
            for (const [prop, value] of Object.entries(record)) {
                if (other[prop] === value) { continue; }
                changed++;
                const id = key.split('|')[1] + ' :: ' + prop + ' :: ' + value + ' → ' + other[prop];
                const group = groups.get(id) || { n: 0, where: new Set() };
                group.n++;
                group.where.add(file.replace('.json', ''));
                groups.set(id, group);
            }
        }
    }

    const sorted = [...groups].sort((x, y) => y[1].n - x[1].n);
    console.log(`Узлов сверено: ${seen}, пропало: ${missing}, различий: ${changed}, групп: ${sorted.length}\n`);
    for (const [id, group] of sorted.slice(0, limit)) {
        console.log(`${String(group.n).padStart(5)}×  ${id}   [${[...group.where].slice(0, 3).join(', ')}]`);
    }
    return { seen, missing, changed };
}

/** Разбор аргументов, общий для обоих срезов. */
export async function main(script, store, capture) {
    const args = process.argv.slice(2);
    if (args[0] === '--diff') {
        if (args.length < 3) { throw new Error('Нужно: --diff <срез-до> <срез-после> [сколько групп]'); }
        diffSnapshots(store, args[1], args[2], Number(args[3] || 60));
        return;
    }
    if (args.length < 1) { throw new Error(`Нужно имя среза: node ${script} before`); }
    await capture(args[0]);
}
