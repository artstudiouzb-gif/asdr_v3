const { test, expect } = require('@playwright/test');

/*
 * Блок в узкой колонке конструктора не вылезает за её край.
 *
 * Сетки считают раскладку медиазапросами по ширине экрана, а доля колонки от
 * экрана не зависит: в раскладке 1:3 узкая колонка на 1440px — это треть
 * планшета. Замерено стендом /container-check (tests/browser/seed_visual.php):
 * «Карточки» шли тремя колонками по сотне пикселей и уезжали за край, лента
 * новостей — четырьмя по 68px, «Новости и документы», «Образование» и
 * «Оргструктура» вылетали на 50–170px. Контейнерные правила
 * (@container cms-col) были, но на редакционной странице их перебивал более
 * поздний слой.
 *
 * Проверка — по вычисленной геометрии, а не по CSS: правило может быть
 * написано и при этом проигрывать каскаду, как и случилось.
 */

const PAGE = '/container-check';

// Карточка уже этого перестаёт читаться: заголовок в два слова ломается по
// буквам. Логотипы партнёров и миниатюры — меньше, у них свой порог.
const MIN_CARD = 160;
const MIN_TILE = 100;
const TILE_GRIDS = ['block-partners__grid'];

const GRIDS = [
    '.imgcards-grid', '.newslist-grid', '.icon-text__grid', '.mediagallery-grid', '.albums-grid', '.cards-grid',
    '.cat-grid', '.block-news__grid', '.block-counters__grid', '.docslist-grid', '.docslist-acts', '.contact-cards',
    '.block-partners__grid', '.block-team__grid', '.block-projects__grid', '.stages', '.newsdocs-news', '.newsdocs-docs',
].join(', ');

for (const width of [1440, 1100]) {
    test(`блоки в колонке 1:3 помещаются на ${width}px`, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop-chromium', 'колонки складываются в столбец на телефоне');
        await page.setViewportSize({ width, height: 900 });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const response = await page.goto(PAGE, { waitUntil: 'networkidle' });
        expect(response && response.status(), 'стенд собран: php tests/browser/seed_visual.php').toBe(200);

        const problems = await page.evaluate(({ grids, minCard, minTile, tileGrids }) => {
            const out = [];
            const sections = [...document.querySelectorAll('.cms-block--columns')];
            if (sections.length < 20) { out.push(`на стенде ${sections.length} блоков — фикстура не накатилась`); }
            for (const sec of sections) {
                const type = (sec.querySelector('.section-head__title')?.textContent || '').trim();
                const col = sec.querySelector('.cms-columns__col');
                const colRect = col.getBoundingClientRect();
                let over = 0;
                let culprit = '';
                col.querySelectorAll('*').forEach((el) => {
                    // Содержимое своей прокрутки (таблица, лента-карусель) не в счёт.
                    for (let a = el.parentElement; a && a !== col; a = a.parentElement) {
                        if (getComputedStyle(a).overflowX !== 'visible') { return; }
                    }
                    const r = el.getBoundingClientRect();
                    if (r.width && r.right - colRect.right > over) {
                        over = r.right - colRect.right;
                        culprit = el.className && typeof el.className === 'string' ? el.className.split(' ')[0] : el.tagName;
                    }
                });
                if (over > 1) { out.push(`${type}: вылет ${Math.round(over)}px (${culprit})`); }

                col.querySelectorAll(grids).forEach((grid) => {
                    const name = grid.className.split(' ')[0];
                    const min = tileGrids.includes(name) ? minTile : minCard;
                    [...grid.children].forEach((card) => {
                        const w = card.getBoundingClientRect().width;
                        if (w > 0 && w < min) { out.push(`${type}: ${name} — карточка ${Math.round(w)}px, нужно не меньше ${min}`); }
                    });
                });
            }
            return [...new Set(out)];
        }, { grids: GRIDS, minCard: MIN_CARD, minTile: MIN_TILE, tileGrids: TILE_GRIDS });

        expect(problems, problems.join('\n')).toEqual([]);
    });
}
