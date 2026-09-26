/*
 * Срез вычисленных стилей публичной части и сравнение двух срезов.
 *
 * Зачем: эталон visual.spec снимает пятнадцать проб с одной витрины, а
 * чистка CSS темы (радиусы, тени, шкала отступов, !important) трогает правила,
 * которые витрина не показывает вовсе. Здесь снимается всё дерево настоящих
 * страниц — главная на двух языках, лента и новость, проекты, каталог,
 * страница с формой, поиск, 404 и сама витрина — в светлой и тёмной теме, на
 * десктопе и на телефоне. Правка считается доказанной, когда в diff остаются
 * только объяснённые различия.
 *
 * Нужны поднятый сервер и демо-контент:
 *   php database/seed_demo.php && php tests/browser/seed_visual.php
 *
 *   node scripts/public-style-snapshot.mjs before
 *   … правка CSS, npm run build:assets …
 *   node scripts/public-style-snapshot.mjs after
 *   node scripts/public-style-snapshot.mjs --diff before after
 *
 * Срезы кладутся в storage/tmp/public-styles/<имя> и в репозиторий не входят.
 *
 * Движение выключено эмуляцией prefers-reduced-motion: иначе появление при
 * скролле оставляло блоки ниже первого экрана прозрачными в одном срезе и
 * видимыми в другом, а автопрокрутка обложки снимала то один слайд, то
 * другой. Цена — правила внутри самого медиазапроса «меньше движения»
 * снимаются в своём, а не в обычном состоянии.
 *
 * Шум: два среза подряд без правок совпадают целиком (замерено: 29 394 узла,
 * 0 различий). Появилось расхождение без правки — сначала повторный срез,
 * потом поиск причины.
 */
import path from 'node:path';
import { ROOT, captureTree, freeze, launchBrowser, main, writeSnapshot } from './lib/style-snapshot.mjs';

const STORE = path.join(ROOT, 'storage/tmp/public-styles');
const BASE = process.env.APP_URL || 'http://127.0.0.1:8080';

const THEMES = ['light', 'dark'];
const VIEWPORTS = {
    desktop: { width: 1440, height: 1000 },
    // 390 — самый массовый телефон; узкие 320px ловит тест вёрстки отдельно.
    mobile: { width: 390, height: 844 },
};

/*
 * Экраны. Адрес записи не зашит: его берём первой ссылкой со страницы
 * списка, иначе срез зависел бы от слагов демо-контента. Не нашлось — экран
 * пропускается с предупреждением, а не роняет весь срез.
 */
const SCREENS = [
    ['home', '/'],
    ['home-uz', '/uz'],
    ['news', '/news'],
    ['news-detail', { from: '/news', pick: '.newslist-grid a[href^="/news/"]' }],
    ['projects', '/projects'],
    ['project-detail', { from: '/projects', pick: 'a[href^="/projects/"]' }],
    ['catalog', '/catalog/documenty'],
    ['catalog-entry', { from: '/catalog/documenty', pick: 'main a[href^="/catalog/documenty/"]' }],
    ['form-page', '/kontakty'],
    ['search', '/search?q=%D0%B0%D0%B3%D0%B5%D0%BD%D1%82%D1%81%D1%82%D0%B2%D0%BE'],
    ['not-found', '/net-takoy-stranicy'],
    ['showcase', '/visual-regression'],
];

async function resolve(page, target) {
    if (typeof target === 'string') { return target; }
    await page.goto(target.from, { waitUntil: 'domcontentloaded' });
    const href = await page.locator(target.pick).first().getAttribute('href').catch(() => null);
    return href && !href.endsWith('.xml') ? href : null;
}

async function capture(name) {
    const out = path.join(STORE, name);
    const browser = await launchBrowser();
    let total = 0;
    let files = 0;
    const skipped = [];

    for (const [viewportName, viewport] of Object.entries(VIEWPORTS)) {
        for (const theme of THEMES) {
            // Тему выставляем ДО загрузки: переключение на живой странице идёт
            // с анимацией. Приём тот же, что в tests/browser/visual.spec.js.
            const context = await browser.newContext({ viewport, baseURL: BASE, reducedMotion: 'reduce' });
            await context.addInitScript((value) => {
                localStorage.setItem('theme', value);
                localStorage.setItem('theme-base', 'light');
            }, theme);
            const page = await context.newPage();

            for (const [screen, target] of SCREENS) {
                const url = await resolve(page, target);
                if (!url) { skipped.push(screen); continue; }
                await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
                await freeze(page);
                await page.waitForTimeout(150);
                total += writeSnapshot(out, `${screen}-${theme}-${viewportName}.json`, await captureTree(page));
                files++;
            }
            await context.close();
        }
    }
    await browser.close();

    console.log(`Срез «${name}»: узлов ${total}, файлов ${files}.`);
    if (skipped.length) {
        console.log(`Пропущено (нет записи в демо-контенте): ${[...new Set(skipped)].join(', ')}`);
    }
}

await main('scripts/public-style-snapshot.mjs', STORE, capture);
