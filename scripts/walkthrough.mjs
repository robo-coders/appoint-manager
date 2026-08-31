/**
 * Phase 12 section 6 — click through the product on the local salon.
 *
 * Hits php artisan serve on :8000 (`appoint_manager`), not the e2e database.
 * Writes screenshots to tests/e2e/.output/walkthrough/. Not part of the gate.
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = 'http://localhost:8000';
const OUT = join(process.cwd(), 'tests/e2e/.output/walkthrough');
const NOTES = [];

mkdirSync(OUT, { recursive: true });

const shot = async (page, name, width) => {
    await page.setViewportSize({ width, height: width === 375 ? 812 : 800 });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
    await page.screenshot({ path: join(OUT, `${name}-${width}.png`), fullPage: true });
};

const shots = async (page, name) => {
    for (const width of [375, 1280]) {
        await shot(page, name, width);
    }
};

const note = (msg) => {
    NOTES.push(msg);
    console.log(msg);
};

const browser = await chromium.launch();
const context = await browser.newContext();
const page = await context.newPage();
page.setDefaultTimeout(15_000);

try {
    await page.goto(`${BASE}/login`);
    await shots(page, '01-login');
    await page.locator('input[type="email"]').fill('owner@rebooking-demo.test');
    await page.locator('input[type="password"]').fill('password');
    await page.waitForTimeout(100);
    note(`login fields email="${await page.locator('input[type="email"]').inputValue()}" passwordLen=${(await page.locator('input[type="password"]').inputValue()).length}`);
    page.on('response', (response) => {
        if (response.url().includes('login') || response.status() >= 400) {
            note(`http ${response.status()} ${response.url()}`);
        }
    });
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForTimeout(2000);
    if (!/\/(diary|dashboard|onboarding)/.test(page.url())) {
        const body = await page.locator('body').innerText();
        note(`login stuck on ${page.url()}: ${body.slice(0, 400).replace(/\s+/g, ' ')}`);
        throw new Error('operator login did not leave /login');
    }
    note(`login → ${page.url()}`);

    await page.goto(`${BASE}/diary`);
    await page.waitForSelector('h1');
    await shots(page, '02-diary');
    note(`diary heading: ${await page.locator('h1').first().innerText()}`);

    const newBooking = page.getByRole('button', { name: /New booking/i });
    if (await newBooking.isVisible()) {
        await newBooking.click();
        const interval = page.getByLabel(/Come back in/i);
        if (await interval.count()) {
            await interval.selectOption('28');
            await shots(page, '03-diary-interval');
            note('interval at checkout: set Come back in to 4 weeks');
        } else {
            note('interval at checkout: New booking opened but Come back in was not on the form');
        }
        await page.keyboard.press('Escape');
    } else {
        note('interval at checkout: no New booking button on this diary day');
    }

    await page.goto(`${BASE}/overdue`);
    await page.waitForSelector('h1');
    await shots(page, '04-overdue');
    note(`overdue heading: ${await page.locator('h1').first().innerText()}`);
    const optedOut = await page.getByText('no texts').count();
    note(`opted-out markers visible: ${optedOut}`);

    const preview = page.getByRole('button', { name: /Preview messages/i });
    if (await preview.isVisible()) {
        await preview.click();
        await page.getByText(/Nothing has been sent|would go out|would be contacted/i).first().waitFor({ timeout: 15_000 });
        await shots(page, '05-overdue-dry-run');
        const body = await page.locator('body').innerText();
        note(`dry run: ${body.match(/[^\n]*(would go out|would be contacted|longer than one)[^\n]*/i)?.[0] ?? 'preview shown'}`);
    } else {
        note('dry run: Preview messages not visible');
    }

    const firstActions = page.getByRole('button', { name: /Actions for/i }).first();
    if (await firstActions.count()) {
        await firstActions.click();
        await page.getByRole('menuitem', { name: 'Snooze two weeks' }).click();
        await page.waitForTimeout(500);
        await shots(page, '06-overdue-snoozed');
        note('snoozed first row two weeks');
    } else {
        note('snooze: no row actions');
    }

    const nextActions = page.getByRole('button', { name: /Actions for/i }).first();
    if (await nextActions.count()) {
        await nextActions.click();
        await page.getByRole('menuitem', { name: 'Stop chasing' }).click();
        await page.waitForTimeout(500);
        await shots(page, '07-overdue-stopped');
        note('stopped chasing next row');
    }

    await page.goto(`${BASE}/billing`);
    await page.waitForSelector('h1');
    await shots(page, '08-billing');
    const billingText = await page.locator('main, [data-page], body').first().innerText();
    note(`billing price line: ${billingText.match(/£[\d.]+/)?.[0] ?? 'no £ found'}`);
    note(`billing allowance: ${billingText.match(/\d+\s+of\s+\d+/)?.[0] ?? billingText.match(/included/) ? 'saw included' : 'no allowance'}`);
    note(`billing top-up: ${/top-up|top up/i.test(billingText) ? 'visible' : 'not visible'}`);

    await context.clearCookies();
    await page.goto(`${BASE}/admin/login`);
    await page.getByLabel('Email').fill('admin@gmail.com');
    await page.getByLabel('Password').fill('admin@1234');
    await page.getByRole('button', { name: 'Sign in' }).click({ noWaitAfter: false });
    await page.waitForURL(/\/admin/);
    await page.waitForSelector('h1');
    await shots(page, '09-admin-tenants');
    note(`admin: ${await page.locator('h1').first().innerText()} — ${page.url()}`);

    const openControls = async () => {
        const actions = page.getByRole('button', { name: /Actions for/i }).first();
        if (!(await actions.count())) return false;
        await actions.click();
        await page.getByRole('menuitem', { name: 'SMS, trial and price' }).click();
        await page.getByLabel('Included allowance').waitFor({ timeout: 8_000 });
        return true;
    };

    if (await openControls()) {
        await shots(page, '10-admin-controls');

        await page.getByLabel('Included allowance').fill('250');
        await page.getByRole('button', { name: 'Set allowance' }).click();
        await page.waitForTimeout(500);
        note('admin: set allowance to 250');

        if (!(await page.getByLabel('Grant texts').count())) await openControls();
        await page.getByLabel('Grant texts').fill('50');
        await page.getByRole('button', { name: 'Grant credit' }).click();
        await page.waitForTimeout(500);
        note('admin: granted 50 texts');

        if (!(await page.getByRole('button', { name: 'Stop SMS now' }).count())) await openControls();
        await page.getByRole('button', { name: 'Stop SMS now' }).click();
        await page.waitForTimeout(500);
        await shots(page, '11-admin-killed');
        note('admin: kill switch');

        if (await page.getByRole('button', { name: 'Allow SMS again' }).count()) {
            await page.getByRole('button', { name: 'Allow SMS again' }).click();
            await page.waitForTimeout(400);
        }

        if (!(await page.getByLabel('Add or subtract days').count())) await openControls();
        await page.getByLabel('Add or subtract days').fill('14');
        await page.getByRole('button', { name: 'Change trial' }).click();
        await page.waitForTimeout(400);
        note('admin: extended trial 14 days');
    } else {
        note('admin: no tenant row actions — console may be empty');
    }

    await page.goto(`${BASE}/admin/messages`);
    await page.waitForSelector('h1');
    await shots(page, '12-admin-send-log');
    note(`send log: ${await page.locator('h1').first().innerText()}`);

    await context.clearCookies();
    await page.goto(`${BASE}/book/rebooking-demo`);
    await page.waitForLoadState('networkidle');
    await shots(page, '13-public-booking');
    note(`public booking: ${page.url()} title=${await page.title()}`);
    const bookText = await page.locator('body').innerText();
    note(`public booking copy: ${bookText.slice(0, 240).replace(/\s+/g, ' ')}`);
} catch (error) {
    note(`FAILED: ${error.message}`);
    await page.screenshot({ path: join(OUT, 'failed.png'), fullPage: true }).catch(() => {});
    throw error;
} finally {
    await browser.close();
    console.log('\n--- walkthrough notes ---');
    for (const line of NOTES) console.log(line);
}
