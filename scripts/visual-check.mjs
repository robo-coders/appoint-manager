#!/usr/bin/env node
/**
 * Shoot a mockup and the screen built from it, at the same width, into a pair
 * of files that can be opened side by side.
 *
 * `.design/mockups/Backend/visual-check/` already held seven such pairs, taken
 * by hand. Taken by hand they drift: the two halves get shot at different
 * widths or on different days, and then a difference between them is as likely
 * to be the camera as the code. This does both halves in one browser, one
 * viewport, one device scale, so what is left over is the screen.
 *
 * Not part of `npm run check`. It renders a design export and drives a running
 * dev server, neither of which belongs in a gate — its output is a picture for
 * a person to look at, and no assertion here could replace looking.
 *
 * Run:  node scripts/visual-check.mjs            # every pair
 *       node scripts/visual-check.mjs 08         # one, by its number
 *
 * The app half needs `php artisan serve` up on APP (below) and `npm run dev`
 * or a built manifest. It fails loudly if a page does not answer — a blank
 * screenshot filed next to a mockup is worse than none, because it looks like
 * a finding.
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const APP = process.env.VISUAL_CHECK_APP ?? 'http://localhost:8000';
const EMAIL = process.env.VISUAL_CHECK_EMAIL ?? 'owner@paw.test';
const PASSWORD = process.env.VISUAL_CHECK_PASSWORD ?? 'password';
const OUT = '.design/mockups/Backend/visual-check';

/*
 * 1440, because that is the width the mockups are drawn at — the artboard in
 * each export is a hard `width:1440px`, so shooting the app at anything else
 * compares two different layouts. At 2x because the mockups' type is the thing
 * being compared and at 1x the antialiasing is most of what you see.
 */
const WIDTH = Number(process.env.VISUAL_CHECK_WIDTH ?? 1440);
const HEIGHT = 1000;
const SCALE = 2;

const PAIRS = [
    {
        id: '08',
        name: 'operator-login',
        mockup: '.design/mockups/frontend/operator-login.html',
        app: '/login',
    },
    {
        id: '09',
        name: 'admin-login',
        mockup: '.design/mockups/frontend/admin-login.html',
        app: '/admin/login',
    },
    {
        id: '10',
        name: 'calendar-sync',
        mockup: '.design/mockups/settings/8 Calendar Sync.dc.html',
        app: '/settings/calendar-sync',
        signIn: true,
    },
    {
        id: '11',
        name: 'client-history',
        mockup: '.design/mockups/customers/client-history.dc.html',
        /*
         * Resolved rather than written down. The record's URL carries a
         * customer id, and the id the demo seed happens to give the first
         * customer is not a fact this file should claim to know — a hardcoded
         * `/customers/1` shoots a 404 and files it as the design.
         */
        app: () => firstCustomerPath(),
        signIn: true,
    },
];

const only = process.argv[2];
const pairs = only ? PAIRS.filter((p) => p.id === only || p.name === only) : PAIRS;

if (pairs.length === 0) {
    console.error(`visual-check: no pair matches "${only}". Known: ${PAIRS.map((p) => `${p.id} (${p.name})`).join(', ')}`);
    process.exit(1);
}

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
    viewport: { width: WIDTH, height: HEIGHT },
    deviceScaleFactor: SCALE,
});
const page = await context.newPage();
page.setDefaultTimeout(20_000);

/** Everything that failed to load, so a missing asset cannot pass as a design. */
let broken = [];
page.on('requestfailed', (r) => broken.push(`${r.failure()?.errorText} ${r.url()}`));
page.on('response', (r) => r.status() >= 400 && broken.push(`HTTP ${r.status()} ${r.url()}`));

/*
 * The first pair whose app half is behind a login, so this exists rather than
 * a second script that knows how to sign in. Credentials come from the
 * environment: the seeded demo owner locally, anything else on another
 * machine, and nothing is committed.
 */
const signIn = async () => {
    await page.goto(`${APP}/login`, { waitUntil: 'networkidle' });

    if (!page.url().includes('/login')) return;

    await page.fill('input[type="email"]', EMAIL);
    await page.fill('input[type="password"]', PASSWORD);
    await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/login')), page.click('button[type="submit"]')]);
};

/** The first row of the Customers list, as a path. */
const firstCustomerPath = async () => {
    await page.goto(`${APP}/customers`, { waitUntil: 'networkidle' });

    const href = await page.locator('a[href*="/customers/"]').first().getAttribute('href');

    if (!href) throw new Error('visual-check: the Customers list is empty — seed the demo data first.');

    return new URL(href, APP).pathname;
};

const shoot = async (url, file) => {
    broken = [];
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    /*
     * The design runtime mounts React after load, so `networkidle` is not the
     * same as "drawn". A settle beat costs a second and saves a screenshot of
     * an empty artboard.
     */
    await page.waitForTimeout(1200);

    const text = (await page.locator('body').innerText()).trim();
    if (text.length < 40) throw new Error(`${url} rendered almost nothing — is the server up?`);

    /*
     * Images are checked rather than assumed. The whole reason this file
     * exists is a logo that was a broken-image icon for weeks: the CSP refused
     * the Vite dev origin on `img-src`, and every screenshot taken by hand in
     * that window filed the broken icon as the design.
     */
    const dead = await page.evaluate(() =>
        [...document.images].filter((i) => !i.complete || i.naturalWidth === 0).map((i) => i.currentSrc || i.src),
    );

    await page.screenshot({ path: `${OUT}/${file}`, fullPage: true });

    return { dead, broken: broken.filter((b) => !b.includes('favicon')) };
};

let failed = false;

for (const pair of pairs) {
    console.log(`\n${pair.id} · ${pair.name}`);

    if (pair.signIn) await signIn();

    const mockupUrl = pathToFileURL(resolve(pair.mockup)).href;
    await shoot(mockupUrl, `${pair.id}-${pair.name}-mockup.png`);
    console.log(`   mockup → ${OUT}/${pair.id}-${pair.name}-mockup.png`);

    const appPath = typeof pair.app === 'function' ? await pair.app() : pair.app;
    const { dead, broken: net } = await shoot(`${APP}${appPath}`, `${pair.id}-${pair.name}-app.png`);
    console.log(`   app    → ${OUT}/${pair.id}-${pair.name}-app.png`);

    for (const src of dead) {
        console.log(`   ⚠ image did not load: ${src}`);
        failed = true;
    }
    for (const problem of net) {
        console.log(`   ⚠ ${problem}`);
        failed = true;
    }
}

await browser.close();

console.log(failed ? '\nvisual-check: shot, with warnings above.' : '\nvisual-check: shot, everything loaded.');
process.exit(failed ? 1 : 0);
