const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');

const script = path.resolve('public/assets/js/blocks/hero.js');
const stylesheet = fs.readFileSync('public/assets/css/blocks/hero.css', 'utf8');

function cover({ id = 'cover', count = 3, autoplay = 3000, video = false, youtube = false } = {}) {
    return `<section class="cms-block"><div id="${id}" class="hero hero--carousel hero--scheme-light"
        data-hero data-hero-autoplay="${autoplay}" data-hero-duration="100" data-hero-swipe tabindex="0">
        <div class="hero__nav"><div class="hero__nav-bar">
        <button data-hero-toggle data-label-play="Play" data-label-pause="Pause" aria-label="Pause">Toggle</button>
        <button data-hero-prev>Previous</button><button data-hero-next>Next</button>
        <span data-hero-current>01</span><span data-hero-progress></span>
        ${Array.from({ length: count }, (_, i) => `<button data-hero-goto="${i}">${i + 1}</button>`).join('')}
        </div></div><span data-hero-status aria-live="polite"></span><div class="hero__slides">
        ${Array.from({ length: count }, (_, i) => `<div class="hero__slide ${i ? '' : 'is-active'}"
            data-hero-slide data-hero-index="${i}" data-hero-scheme="${i === 1 ? 'light' : 'dark'}" aria-label="${i + 1} of ${count}">
            ${video && i === 0 ? '<video data-hero-video data-hero-mobile-media="play" hidden preload="none"><source data-hero-src="/test.mp4"></video>' : ''}
            ${youtube && i === 0 ? '<div data-hero-youtube data-hero-yt-src="https://www.youtube-nocookie.com/embed/test"></div>' : ''}
            <div class="hero__inner"><a href="#outside">Slide ${i + 1}</a><input aria-label="Editor ${i + 1}" value="Text"></div>
        </div>`).join('')}</div></div></section>`;
}

async function setup(page, options = {}, extra = '') {
    await page.setViewportSize({ width: 1200, height: 900 });
    await page.route('https://www.youtube-nocookie.com/**', route => route.fulfill({ body: '<html></html>', contentType: 'text/html' }));
    await page.setContent(`<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
        <header class="site-header--transparent"></header>${cover(options)}${extra}<button id="outside">Outside</button>
        <div style="height:2000px"></div></body></html>`);
    await page.addStyleTag({ content: `:root { --color-accent: #087b81; --gov-navy:#123; --space-xs:8px; --space-s:16px; --space-l:24px; }
        .hero { --hero-min-h:300px; } .hero__inner { position:relative; } ${stylesheet}` });
    await page.evaluate(() => {
        window.mediaLog = [];
        HTMLMediaElement.prototype.load = function () {};
        HTMLMediaElement.prototype.play = function () {
            window.mediaLog.push('play');
            this.dispatchEvent(new Event('playing'));
            return Promise.resolve();
        };
        HTMLMediaElement.prototype.pause = function () { window.mediaLog.push('pause'); };
    });
    await page.addScriptTag({ path: script });
    await expect(page.locator('#cover')).toHaveAttribute('data-hero-ready', '');
    await expect.poll(() => page.locator('#cover').getAttribute('data-hero-paused')).toBe('false');
    await page.clock.install();
}

const current = page => page.locator('#cover [data-hero-current]');
const next = page => page.locator('#cover [data-hero-next]');
const toggle = page => page.locator('#cover [data-hero-toggle]');

// These run the real production runtime and CSS. Media transport is stubbed so
// lifecycle, rejected play promises and cross-origin messages are deterministic.
test('focus pause survives mouseleave and time; only explicit play restarts', async ({ page }) => {
    await setup(page);
    await next(page).focus();
    await page.locator('#cover').dispatchEvent('mouseleave');
    await page.locator('#outside').focus();
    await page.clock.fastForward(20000);
    await expect(current(page)).toHaveText('01');
    await expect(toggle(page)).toHaveAttribute('aria-label', 'Play');
    await toggle(page).click();
    await page.clock.fastForward(3100);
    await expect(current(page)).toHaveText('02');
});

test('hover and hidden tab cannot cancel one another', async ({ page }) => {
    await setup(page);
    await page.locator('#cover').dispatchEvent('mouseenter');
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.locator('#cover').dispatchEvent('mouseleave');
    await page.clock.fastForward(5000);
    await expect(current(page)).toHaveText('01');
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: false });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.clock.fastForward(3100);
    await expect(current(page)).toHaveText('02');
});

test('manual navigation stays paused and announces only requested slides', async ({ page }) => {
    await setup(page);
    await next(page).click();
    await page.clock.fastForward(15000);
    await expect(current(page)).toHaveText('02');
    await expect(page.locator('[data-hero-status]')).toHaveText('2 of 3');
    await expect(page.locator('[data-hero-slide][inert]')).toHaveCount(2);
    await expect(page.locator('[data-hero-slide].is-active')).toHaveAttribute('aria-hidden', 'false');
    await toggle(page).click();
    await page.clock.fastForward(3100);
    await expect(page.locator('[data-hero-status]')).toHaveText('');
});

test('per-slide duration controls the next advance', async ({ page }) => {
    await setup(page);
    await page.locator('[data-hero-index="1"]').evaluate(el => el.setAttribute('data-hero-slide-duration', '7000'));
    await page.clock.fastForward(3100);
    await expect(current(page)).toHaveText('02');
    await page.clock.fastForward(4000);
    await expect(current(page)).toHaveText('02');
    await page.clock.fastForward(3100);
    await expect(current(page)).toHaveText('03');
});

test('rapid return clears obsolete exit and header transitions', async ({ page }) => {
    await setup(page);
    await page.evaluate(() => {
        document.querySelector('[data-hero-next]').click();
        document.querySelector('[data-hero-prev]').click();
    });
    await page.clock.fastForward(500);
    await expect(current(page)).toHaveText('01');
    await expect(page.locator('[data-hero-slide].is-active.is-leaving')).toHaveCount(0);
    await expect(page.locator('body')).not.toHaveClass(/is-hero-light/);
});

test('mobile initially pauses rotation but accepts an explicit start', async ({ page }) => {
    await setup(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(toggle(page)).toHaveAttribute('aria-label', 'Play');
    await page.clock.fastForward(6000);
    await expect(current(page)).toHaveText('01');
    await toggle(page).click();
    await page.clock.fastForward(3100);
    await expect(current(page)).toHaveText('02');
});

test('reduced motion disables playback live and does not silently resume', async ({ page }) => {
    await setup(page, { video: true });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await expect(toggle(page)).toBeDisabled();
    await expect(page.locator('video')).toBeHidden();
    await page.clock.fastForward(5000);
    await expect(current(page)).toHaveText('01');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await expect(toggle(page)).toBeEnabled();
    await expect(toggle(page)).toHaveAttribute('aria-label', 'Play');
});

test('single-video cover stops outside viewport and in a hidden page', async ({ page }) => {
    await setup(page, { count: 1, autoplay: 0, video: true });
    const video = page.locator('video');
    await expect(video).not.toHaveAttribute('hidden');
    await page.evaluate(() => window.scrollTo(0, 1800));
    await expect(page.locator('#cover')).toHaveAttribute('data-hero-paused', 'true');
    await expect(video).toHaveAttribute('hidden');
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(video).not.toHaveAttribute('hidden');
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await expect(video).toHaveAttribute('hidden');
});

test('rejection from an old video session cannot stop a newer play', async ({ page }) => {
    await setup(page, { count: 1, autoplay: 0, video: true });
    await toggle(page).click();
    await page.evaluate(() => {
        HTMLMediaElement.prototype.play = function () {
            this.dispatchEvent(new Event('playing'));
            return new Promise((resolve, reject) => { (window.pendingPlays ||= []).push({ resolve, reject }); });
        };
    });
    await toggle(page).click();
    await toggle(page).click();
    await toggle(page).click();
    await page.evaluate(() => window.pendingPlays[0].reject(new Error('old attempt')));
    await expect(page.locator('video')).not.toHaveAttribute('hidden');
});

test('YouTube requires a matching origin and PLAYING, and cancels old timeouts', async ({ page }) => {
    await setup(page, { youtube: true, autoplay: 0 });
    const box = page.locator('[data-hero-youtube]');
    async function message(origin, payload) {
        await page.evaluate(({ origin, payload }) => {
            const frame = document.querySelector('iframe');
            window.dispatchEvent(new MessageEvent('message', { origin, source: frame.contentWindow, data: JSON.stringify(payload) }));
        }, { origin, payload });
    }
    await message('https://attacker.example', { event: 'infoDelivery', info: { playerState: 1 } });
    await expect(box).not.toHaveClass(/is-ready/);
    await message('https://www.youtube-nocookie.com', { event: 'onReady' });
    await expect(box).not.toHaveClass(/is-ready/);
    await page.clock.fastForward(2000);
    await toggle(page).click();
    await toggle(page).click();
    await message('https://www.youtube-nocookie.com', { event: 'infoDelivery', info: { playerState: 1 } });
    await page.clock.fastForward(5000);
    await expect(box).toHaveClass(/is-ready/);
    await expect(box.locator('iframe')).toHaveCount(1);
});

test('unavailable YouTube leaves the poster instead of a failed iframe', async ({ page }) => {
    await setup(page, { youtube: true, autoplay: 0 });
    await page.clock.fastForward(4100);
    await expect(page.locator('[data-hero-youtube] iframe')).toHaveCount(0);
    await expect(page.locator('[data-hero-youtube]')).toBeHidden();
});

test('instances are independent and reinitialization does not duplicate listeners', async ({ page }) => {
    await setup(page, {}, cover({ id: 'second', autoplay: 0 }));
    await page.evaluate(() => {
        document.dispatchEvent(new Event('asdr:hero-init'));
        document.dispatchEvent(new Event('asdr:hero-init'));
    });
    await next(page).click();
    await expect(current(page)).toHaveText('02');
    await expect(page.locator('#second [data-hero-current]')).toHaveText('01');
    await page.locator('#cover').dispatchEvent('asdr:hero-destroy');
    await expect(page.locator('#cover [inert]')).toHaveCount(0);
    await page.evaluate(() => document.dispatchEvent(new Event('asdr:hero-init')));
    await next(page).click();
    await expect(current(page)).toHaveText('02');
});

test('arrow keys do not hijack input editing or browser shortcuts', async ({ page }) => {
    await setup(page);
    const input = page.locator('[data-hero-index="0"] input');
    await input.focus();
    await input.press('ArrowRight');
    await expect(current(page)).toHaveText('01');
    await next(page).focus();
    await next(page).press('Control+ArrowRight');
    await expect(current(page)).toHaveText('01');
    await next(page).press('ArrowRight');
    await expect(current(page)).toHaveText('02');
});

test('failed enhancement keeps all slides reachable by native scrolling', async ({ page }) => {
    await setup(page);
    await page.locator('#cover').dispatchEvent('asdr:hero-destroy');
    await expect(page.locator('.hero__nav')).toBeHidden();
    await expect(page.locator('[data-hero-slide][aria-hidden]')).toHaveCount(0);
    await page.locator('[data-hero-index="2"] a').focus();
    const position = await page.locator('.hero__slides').evaluate(el => ({ scroll: el.scrollLeft, width: el.clientWidth }));
    expect(position.scroll).toBeGreaterThan(position.width);
});

test('swipe changes slides; vertical, cancelled and multitouch gestures do not', async ({ page }) => {
    await setup(page);
    async function gesture(events) {
        await page.evaluate(events => {
            const root = document.querySelector('#cover');
            for (const [type, touches, changedTouches = []] of events) {
                const event = new Event(type, { bubbles: true });
                Object.defineProperties(event, { touches: { value: touches }, changedTouches: { value: changedTouches } });
                root.dispatchEvent(event);
            }
        }, events);
    }
    const start = { identifier: 1, clientX: 200, clientY: 100 };
    const left = { identifier: 1, clientX: 100, clientY: 105 };
    await gesture([['touchstart', [start]], ['touchcancel', []], ['touchend', [], [left]]]);
    await expect(current(page)).toHaveText('01');
    await gesture([['touchstart', [start]], ['touchend', [], [{ ...left, clientY: 400 }]]]);
    await expect(current(page)).toHaveText('01');
    await gesture([['touchstart', [start]], ['touchstart', [start, { ...start, identifier: 2 }]], ['touchend', [], [left]]]);
    await expect(current(page)).toHaveText('01');
    await gesture([['touchstart', [start]], ['touchend', [], [left]]]);
    await expect(current(page)).toHaveText('02');
});
