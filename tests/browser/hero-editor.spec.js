const { test, expect } = require('@playwright/test');
const path = require('node:path');
const script = path.resolve('public/assets/js/admin-hero-settings.js');
function select(name, choices) {
    return `<div class="form-field"><select name="${name}">${choices.map(v => `<option value="${v}">${v || 'Inherited'}</option>`).join('')}</select></div>`;
}
function input(name, type = 'text', value = '') {
    return `<div class="form-field"><input name="${name}" type="${type}" value="${value}"></div>`;
}
async function setup(page, kind, body) {
    await page.setContent(`<form data-hero-editor="${kind}" data-hero-overlay-default="gradient">${body}</form>`);
    await page.addStyleTag({ content: '[data-hero-dependent][hidden] { display:none }' });
    await page.addScriptTag({ path: script });
}

test('cover shows dependent fields and keeps hidden values in the form payload', async ({ page }) => {
    await setup(page, 'cover', select('height', ['regular', 'custom']) + input('height_value', 'number', '680')
        + select('scheme', ['navy', 'custom']) + select('content_scheme', ['auto', 'light'])
        + input('scheme_bg') + input('scheme_text') + input('panel', 'checkbox') + input('panel_color'));
    await expect(page.locator('[name=height_value]')).toBeHidden();
    await page.selectOption('[name=height]', 'custom');
    await expect(page.locator('[name=height_value]')).toBeVisible();
    await page.locator('[name=height_value]').fill('720');
    await page.selectOption('[name=height]', 'regular');
    expect(await page.locator('form').evaluate(form => new FormData(form).get('height_value'))).toBe('720');
    await page.selectOption('[name=scheme]', 'custom');
    await expect(page.locator('[name=scheme_text]')).toBeVisible();
    await page.selectOption('[name=content_scheme]', 'light');
    await expect(page.locator('[name=scheme_text]')).toBeHidden();
});

test('slide exposes the correct video source and mobile MP4 field', async ({ page }) => {
    await setup(page, 'slide', select('media_type', ['none', 'image', 'video', 'youtube'])
        + input('image') + input('video_url') + input('youtube_url') + input('poster')
        + select('mobile_media', ['image', 'desktop', 'mobile_video']) + input('video_mobile_url'));
    await expect(page.locator('[name=video_url]')).toBeHidden();
    await page.selectOption('[name=media_type]', 'video');
    await expect(page.locator('[name=video_url]')).toBeVisible();
    await expect(page.locator('[name=youtube_url]')).toBeHidden();
    await page.selectOption('[name=mobile_media]', 'mobile_video');
    await expect(page.locator('[name=video_mobile_url]')).toBeVisible();
    await page.selectOption('[name=media_type]', 'youtube');
    await expect(page.locator('[name=youtube_url]')).toBeVisible();
    await expect(page.locator('[name=video_mobile_url]')).toBeHidden();
    await expect(page.locator('[name=mobile_media]')).toHaveValue('image');
});

test('slide CTA and inherited gradient controls respond without losing values', async ({ page }) => {
    await setup(page, 'slide', input('cta_enabled', 'checkbox') + input('cta_text', 'text', 'Подробнее')
        + input('cta_url', 'text', '/page') + select('overlay', ['', 'none', 'solid', 'gradient'])
        + input('overlay_color') + input('overlay_opacity') + select('overlay_direction', ['auto', 'to_left']));
    await expect(page.locator('[name=cta_text]')).toBeHidden();
    await page.check('[name=cta_enabled]');
    await expect(page.locator('[name=cta_text]')).toHaveValue('Подробнее');
    await expect(page.locator('[name=overlay_direction]')).toBeVisible();
    await page.selectOption('[name=overlay]', 'none');
    await expect(page.locator('[name=overlay_color]')).toBeHidden();
});

test('slide asks for a colour only from the button style that uses it', async ({ page }) => {
    // Мёртвых полей на экране нет: заливку слушает вид «Основная», цвет
    // ссылки — вид «Ссылка», у остальных видов оба поля ничего не меняют.
    await setup(page, 'slide', input('cta_enabled', 'checkbox') + select('cta_style', ['primary', 'link'])
        + input('cta2_enabled', 'checkbox') + select('cta2_style', ['ghost', 'link'])
        + input('cta_color', 'color') + input('link_color', 'color') + input('cta_text_color', 'color'));
    await expect(page.locator('[name=cta_color]')).toBeHidden();
    await expect(page.locator('[name=cta_text_color]')).toBeHidden();
    await expect(page.locator('[name=link_color]')).toBeHidden();
    await page.check('[name=cta_enabled]');
    await expect(page.locator('[name=cta_color]')).toBeVisible();
    await expect(page.locator('[name=cta_text_color]')).toBeVisible();
    await expect(page.locator('[name=link_color]')).toBeHidden();
    await page.selectOption('[name=cta_style]', 'link');
    await expect(page.locator('[name=cta_color]')).toBeHidden();
    await expect(page.locator('[name=link_color]')).toBeVisible();
    await page.check('[name=cta2_enabled]');
    await page.selectOption('[name=cta2_style]', 'ghost');
    await expect(page.locator('[name=cta_color]')).toBeHidden();
});

test('schedule validates bounds and collapsed summary updates immediately', async ({ page }) => {
    await setup(page, 'cover', select('status', ['draft', 'scheduled', 'published'])
        + input('published_from', 'datetime-local') + input('published_to', 'datetime-local')
        + '<details data-hero-summary="autoplay,autoplay_interval"><summary><span class="form-section__state">Old</span></summary>'
        + input('autoplay', 'checkbox') + input('autoplay_interval', 'number', '6') + '</details>');
    await expect(page.locator('.form-section__state')).toHaveText('Выключена');
    await page.selectOption('[name=status]', 'scheduled');
    expect(await page.locator('form').evaluate(form => form.checkValidity())).toBe(false);
    await page.locator('[name=published_from]').fill('2026-09-10T10:00');
    await page.locator('[name=published_to]').fill('2026-09-09T10:00');
    expect(await page.locator('form').evaluate(form => form.checkValidity())).toBe(false);
    await page.selectOption('[name=status]', 'published');
    expect(await page.locator('form').evaluate(form => form.checkValidity())).toBe(true);
    await page.locator('summary').click();
    await page.check('[name=autoplay]');
    await expect(page.locator('.form-section__state')).toHaveText('Включена · 6 с');
});
