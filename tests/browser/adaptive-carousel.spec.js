const path = require('node:path');
const { test, expect } = require('@playwright/test');

const projectRoot = path.resolve(__dirname, '../..');

async function mountCarousel(page) {
    await page.setContent(`
        <meta name="asdr-icon-sprite" content="/assets/vendor/tabler/tabler-sprite.svg">
        <script type="application/json" id="frontend-labels">
            {"goToSlide":"Перейти к слайду"}
        </script>
        <div data-carousel style="width:620px">
            <span class="carousel-nav" data-carousel-nav hidden>
                <button type="button" data-carousel-prev>Назад</button>
                <span data-carousel-dots></span>
                <button type="button" data-carousel-next>Вперёд</button>
            </span>
            <div data-carousel-track tabindex="0"
                 style="display:flex;width:620px;gap:20px;overflow-x:auto;scroll-snap-type:x mandatory">
                ${Array.from({ length: 6 }, (_, index) => `
                    <article data-carousel-item
                             style="flex:0 0 280px;scroll-snap-align:start">Элемент ${index + 1}</article>
                `).join('')}
            </div>
        </div>
    `);
    await page.addStyleTag({ path: path.join(projectRoot, 'public/assets/css/gov-theme.css') });
    await page.addScriptTag({ path: path.join(projectRoot, 'public/assets/js/frontend.js') });
}

test('адаптивная карусель показывает страницы и управляется с клавиатуры', async ({ page }) => {
    await mountCarousel(page);

    const nav = page.locator('[data-carousel-nav]');
    const track = page.locator('[data-carousel-track]');
    await expect(nav).toBeVisible();
    expect(await page.locator('.carousel-nav__dot').count()).toBeGreaterThan(1);
    await expect(page.locator('.carousel-nav__dot').first()).toHaveAttribute('aria-current', 'true');

    await track.focus();
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => track.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
    await expect(page.locator('[data-carousel-prev]')).toBeEnabled();

    await page.keyboard.press('End');
    await expect.poll(() => track.evaluate((element) => (
        Math.round(element.scrollWidth - element.clientWidth - element.scrollLeft)
    ))).toBeLessThanOrEqual(2);
    await expect(page.locator('[data-carousel-next]')).toBeDisabled();
});

test('reduced motion отключает плавную прокрутку карусели', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await mountCarousel(page);

    await expect(page.locator('[data-carousel-track]')).toHaveCSS('scroll-behavior', 'auto');
});
