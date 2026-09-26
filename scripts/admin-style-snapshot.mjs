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
 * Тёмный вид задаётся атрибутом data-admin-appearance. Прежде срез ставил
 * data-admin-theme="dark_emerald" — такой темы давно нет, а на атрибуте
 * data-admin-theme="default" висят объявления токенов панели, поэтому
 * «тёмный» срез снимал не тёмную панель, а панель без токенов.
 *
 * Шум: два среза подряд без правок совпадают целиком (замерено после
 * перехода на data-admin-appearance: 34 308 узлов, 0 различий). Прежние
 * расхождения десятых долей цвета на форме новости снимались с панели без
 * токенов и к правкам отношения не имели.
 */
import crypto from 'node:crypto';
import path from 'node:path';
import { ROOT, captureTree, freeze, launchBrowser, main, writeSnapshot } from './lib/style-snapshot.mjs';

const STORE = path.join(ROOT, 'storage/tmp/admin-styles');
const BASE = process.env.APP_URL || 'http://127.0.0.1:8080';

// Те же значения, что в tests/browser/seed_admin_visual.php.
const USER = 'visual';
const PASSWORD = 'Visual-regression-1';
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

// Те же состояния, что в tests/browser/admin-visual.spec.js.
const THEMES = ['light', 'dark'];

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
    await page.evaluate((value) => document.documentElement.setAttribute('data-admin-appearance', value), theme);
    await freeze(page);
    await page.waitForTimeout(150);
    return writeSnapshot(out, `${screen}-${theme}.json`, await captureTree(page));
}

async function capture(name) {
    const out = path.join(STORE, name);
    const browser = await launchBrowser();
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

await main('scripts/admin-style-snapshot.mjs', STORE, capture);
