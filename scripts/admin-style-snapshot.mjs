/*
 * Срез вычисленных стилей админки и сравнение двух срезов.
 *
 * Зачем: слой «Enterprise Scale» в admin.css перебивает компоненты через
 * !important, и снимать эти строки на глаз нельзя — правило может оказаться
 * единственным местом, где компонент вообще оформлен. Эталон admin-visual.spec
 * снимает десяток селекторов на экран; здесь снимается всё дерево двенадцати
 * экранов в обеих темах (~28 000 узлов), и разбор семьи правил считается
 * доказанным, когда в diff остаются только объяснённые различия.
 *
 * Нужны поднятый сервер и фикстура: php tests/browser/seed_admin_visual.php.
 *
 *   node scripts/admin-style-snapshot.mjs before
 *   … правка CSS …
 *   node scripts/admin-style-snapshot.mjs after
 *   node scripts/admin-style-snapshot.mjs --diff before after
 *
 * Срезы кладутся в storage/tmp/admin-styles/<имя> и в репозиторий не входят.
 *
 * Шум: на экране формы новости в тёмной теме между двумя срезами без единой
 * правки CSS расходятся десятые доли цвета (#f8fafc против #f6f8fa) — форма
 * инициализирует редактор и виджеты цвета уже после загрузки. Такие группы в
 * diff — не следствие правки; проверяется повторным срезом без изменений.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { chromium } from '@playwright/test';

const ROOT = path.resolve(import.meta.dirname, '..');
const STORE = path.join(ROOT, 'storage/tmp/admin-styles');
const BASE = process.env.APP_URL || 'http://127.0.0.1:8080';

// Те же значения, что в tests/browser/seed_admin_visual.php.
const USER = 'visual';
const PASSWORD = 'Visual-regression-1';
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

const THEMES = ['default', 'dark_emerald'];
const SCREENS = [
    ['dashboard', '/admin'],
    ['news-list', '/admin/news'],
    ['news-form', '/admin/news/create'],
    ['pages-list', '/admin/pages'],
    ['settings', '/admin/settings'],
    ['design', '/admin/design'],
    ['header', '/admin/header'],
    ['footer', '/admin/footer'],
    ['files', '/admin/files'],
    ['users', '/admin/users'],
    ['telegram', '/admin/telegram'],
    ['database', '/admin/database'],
    // Обложки: слайды рисует собственная секция слоя переопределений.
    ['heroes', '/admin/heroes'],
];

// Экран входа рисуется мимо общей оболочки и своей секцией правил, но снять
// его можно только до авторизации — поэтому он отдельным списком.
const ANON_SCREENS = [
    ['login', '/admin/login'],
];
const PROPS = [
    'display', 'position', 'boxSizing', 'width', 'height', 'minHeight', 'maxWidth',
    'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
    'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform',
    'color', 'backgroundColor', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth',
    'borderLeftWidth', 'borderTopColor', 'borderBottomColor', 'borderRadius',
    'flexDirection', 'alignItems', 'justifyContent', 'gap', 'gridTemplateColumns',
    'opacity', 'overflowX', 'overflowY', 'textAlign', 'whiteSpace', 'boxShadow', 'accentColor',
];

function totp(secret, step = Math.floor(Date.now() / 1000 / 30)) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret) {
        const index = alphabet.indexOf(char);
        if (index >= 0) { bits += index.toString(2).padStart(5, '0'); }
    }
    const key = Buffer.from((bits.match(/.{8}/g) || []).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeUInt32BE(Math.floor(step / 2 ** 32), 0);
    counter.writeUInt32BE(step >>> 0, 4);
    const digest = crypto.createHmac('sha1', key).update(counter).digest();
    const offset = digest[digest.length - 1] & 0x0f;
    const binary = ((digest[offset] & 0x7f) << 24) | ((digest[offset + 1] & 0xff) << 16)
        | ((digest[offset + 2] & 0xff) << 8) | (digest[offset + 3] & 0xff);
    return String(binary % 1000000).padStart(6, '0');
}

/** Шаг TOTP одноразовый, поэтому при отказе предъявляем код следующего окна. */
async function login(page) {
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt === 2) { await page.waitForTimeout(31000); }
        await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
        await page.fill('#username', USER);
        await page.fill('#password', PASSWORD);
        await page.click('form button[type="submit"]');
        await page.waitForLoadState();
        if (/\/admin\/?$/.test(page.url())) { return; }
        if (!page.url().includes('/admin/login/2fa')) {
            throw new Error('Вход остановился на ' + page.url() + ' — проверьте tests/browser/seed_admin_visual.php');
        }
        const step = Math.floor(Date.now() / 1000 / 30) + (attempt === 1 ? 1 : 0);
        await page.fill('#code', totp(SECRET, step));
        await page.click('form button[type="submit"]');
        await page.waitForLoadState();
        if (/\/admin\/?$/.test(page.url())) { return; }
    }
    throw new Error('Второй фактор не принят за три попытки: шаг TOTP занят другим входом?');
}

/** Снимает один экран в одной теме; возвращает число снятых узлов. */
async function captureScreen(page, screen, url, theme, out) {
    await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
    await page.evaluate((value) => document.documentElement.setAttribute('data-admin-theme', value), theme);
    // Переходы гасим: срез ловил цвет посреди смены темы.
    await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important}' });
    await page.waitForTimeout(150);
    const data = await page.evaluate((props) => {
        const result = {};
        const walk = (el, keyPath) => {
            const computed = getComputedStyle(el);
            const record = {};
            for (const prop of props) { record[prop] = computed[prop]; }
            const cls = typeof el.className === 'string'
                ? el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
            result[keyPath + '|' + el.tagName.toLowerCase() + (cls ? '.' + cls : '')] = record;
            [...el.children].forEach((child, i) => walk(child, keyPath + '>' + i));
        };
        walk(document.body, '0');
        return result;
    }, PROPS);
    fs.writeFileSync(path.join(out, `${screen}-${theme}.json`), JSON.stringify(data));
    return Object.keys(data).length;
}

async function capture(name) {
    const out = path.join(STORE, name);
    fs.mkdirSync(out, { recursive: true });
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, baseURL: BASE });
    const page = await context.newPage();

    let total = 0;
    for (const [screen, url] of ANON_SCREENS) {
        for (const theme of THEMES) {
            total += await captureScreen(page, screen, url, theme, out);
        }
    }

    await login(page);
    for (const [screen, url] of SCREENS) {
        for (const theme of THEMES) {
            total += await captureScreen(page, screen, url, theme, out);
        }
    }
    console.log(`Срез «${name}»: узлов ${total}, файлов ${(SCREENS.length + ANON_SCREENS.length) * THEMES.length}.`);
    await browser.close();
}

function diff(a, b, limit) {
    const dirA = path.join(STORE, a);
    const dirB = path.join(STORE, b);
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
}

const args = process.argv.slice(2);
if (args[0] === '--diff') {
    if (args.length < 3) { throw new Error('Нужно: --diff <срез-до> <срез-после> [сколько групп]'); }
    diff(args[1], args[2], Number(args[3] || 60));
} else {
    if (args.length < 1) { throw new Error('Нужно имя среза: node scripts/admin-style-snapshot.mjs before'); }
    await capture(args[0]);
}
