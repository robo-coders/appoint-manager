import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { DEMO } from './support';

/**
 * Screenshot snapshots, at the three widths the design system has opinions
 * about.
 *
 * These are the regression net for the class of bug `vue-tsc` cannot see and a
 * component test only catches if somebody thought to assert it: a label clipped
 * by half a line, a row that has started wrapping, a rail that has lost its
 * icons, a column that has quietly become two lines tall. The phase 7 report
 * found both of its rendering bugs by taking a screenshot and looking at one.
 *
 * They are deliberately whole-page and deliberately few. A snapshot per
 * component is a suite that fails on every intentional change and gets deleted;
 * four screens at three widths is a net that catches the things that were
 * actually caught by eye.
 *
 * `maxDiffPixelRatio` in the config is loose enough to survive font
 * rasterisation on another machine. Update with `--update-snapshots` after
 * looking at the diff — never before.
 *
 * No `signIn` here: the `operator` project carries the session `auth.setup.ts`
 * saved. Signing in per spec is nine logins in two minutes against a limiter
 * that allows five, and going to `/login` with a valid session redirects
 * straight back out — so the form is never there to fill in.
 */

const WIDTHS = [
    { name: '375', width: 375, height: 900 },
    { name: '768', width: 768, height: 1000 },
    { name: '1280', width: 1280, height: 1000 },
] as const;

/**
 * Pin the browser clock — `setFixedTime`, never `install`.
 *
 * `install()` pauses time altogether, so `setTimeout` never fires. Inertia's
 * progress bar and `document.fonts.ready` are both timer-driven, so the page
 * never reaches `load` and every spec times out at sixty seconds looking like a
 * pixel diff. `setFixedTime` freezes what `Date.now()` reports and leaves timers
 * running, which is the half of it these snapshots need.
 *
 * The value is `FROZEN_NOW`, shared with the server — which is frozen to the
 * same instant by `FREEZE_NOW`, and seeded at it. Freezing only the browser was
 * the old arrangement and it fixed nothing, because everything in these frames
 * that moves is computed in PHP.
 */
async function freezeTime(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
}

/**
 * The regions that count minutes on the *server*, which no browser clock can
 * freeze.
 *
 * The dashboard's current appointment says "In the chair 31 min", computed in
 * PHP from `now()`; the diary's current-time hairline is positioned from a
 * server-sent `now`. Both drift while a suite runs. Masking is honest here —
 * everything a snapshot is *for* (layout, wrapping, clipping, the rail, the row
 * rhythm) is outside these two boxes.
 */
function volatileRegions(page: Page) {
    return [page.locator('.pl-sub-indent'), page.locator('[role="timer"]')];
}

/** Wait for the fonts the design depends on, or the first run snapshots Arial. */
async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

test.describe('the operator app', () => {
    for (const size of WIDTHS) {
        test(`diary at ${size.name}`, async ({ page }) => {
            await freezeTime(page);
            await page.setViewportSize({ width: size.width, height: size.height });
            await page.goto('/diary?date=2026-08-26');
            await expect(page.getByRole('heading', { name: /August/ })).toBeVisible();
            await settled(page);

            await expect(page).toHaveScreenshot(`diary-${size.name}.png`, {
                fullPage: true,
                mask: volatileRegions(page),
            });
        });

        test(`dashboard at ${size.name}`, async ({ page }) => {
            await freezeTime(page);
            await page.setViewportSize({ width: size.width, height: size.height });
            await page.goto('/dashboard');
            await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
            await settled(page);

            await expect(page).toHaveScreenshot(`dashboard-${size.name}.png`, {
                fullPage: true,
                mask: volatileRegions(page),
            });
        });

        test(`bookings table at ${size.name}`, async ({ page }) => {
            await freezeTime(page);
            await page.setViewportSize({ width: size.width, height: size.height });
            await page.goto('/bookings');
            await expect(page.getByRole('heading', { name: 'Bookings' })).toBeVisible();
            await settled(page);

            // Not `fullPage`: the table is long and its length is a property of
            // the seed, not of the design. The viewport holds the header, the
            // filters and enough rows to see the row rhythm.
            await expect(page).toHaveScreenshot(`bookings-${size.name}.png`, { mask: volatileRegions(page) });
        });
    }
});

/*
 * Customers at 375, and only at 375.
 *
 * This screen had never been looked at on a phone. At 375px the names broke over
 * two lines ("Ade / Oyelaran"), the rows went ragged as some wrapped and some
 * did not, and email and phone — both `secondary`, so both hidden below md —
 * were gone with no way to reach either. It is a list of rows there now rather
 * than a squeezed table: name, email under it, the number hard right as a `tel:`
 * link. See `ui/Table`, "the narrow state".
 *
 * One width because there is one thing here a snapshot can catch that the other
 * screens do not already cover: whether the narrow layout is still the narrow
 * layout. The wide table is the same component the bookings snapshots exercise
 * at 768 and 1280, and three baselines for one screen is three files to
 * regenerate every time a row gains a pixel.
 *
 * Not `fullPage`: 72 customers is a property of the seed, not of the design. The
 * viewport holds the header, the search field and enough rows to see that they
 * are all the same height — which is the thing that was wrong.
 */
test('customers at 375, where it is a list and not a table', async ({ page }) => {
    await freezeTime(page);
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/customers');
    await expect(page.getByRole('heading', { name: 'Customers' })).toBeVisible();
    await settled(page);

    /*
     * Assertions as well as a picture, because a snapshot cannot tell you *why*
     * it changed. If the narrow layout is ever dropped, these say so in one line
     * instead of as a pixel diff somebody has to open.
     */
    const first = page.locator('ul[aria-label="Customers"] > li').first();
    await expect(first).toBeVisible();
    // One tap to ring them, not a menu and a second screen.
    await expect(first.locator('a[href^="tel:"]')).toBeVisible();
    // The table itself is hidden at this width rather than scrolling sideways.
    await expect(page.locator('table[aria-label="Customers"]')).toBeHidden();
    // And the page does not scroll sideways, which is the failure this replaces.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await expect(page).toHaveScreenshot('customers-375.png', { mask: volatileRegions(page) });
});

test.describe('the booking page', () => {
    for (const size of WIDTHS) {
        test(`proposal at ${size.name}`, async ({ page }) => {
            await freezeTime(page);
            await page.setViewportSize({ width: size.width, height: size.height });

            await page.goto(`/book/${DEMO.slug}`);
            await expect(page.locator('h1.text-34')).toBeVisible();
            await settled(page);

            await expect(page).toHaveScreenshot(`booking-proposal-${size.name}.png`, { fullPage: true });
        });
    }

    /*
     * The picker is where the two rendering rules that are easiest to break
     * live: unavailable times keep their place struck through, and the day rail
     * fills only the selected day. Worth its own frame.
     */
    test('the fallback picker at 375', async ({ page }) => {
        await freezeTime(page);
        await page.setViewportSize({ width: 375, height: 1100 });

        await page.goto(`/book/${DEMO.slug}`);
        await page.getByRole('button', { name: 'Pick another day' }).click();
        await expect(page.getByRole('heading', { name: 'Pick a day' })).toBeVisible();
        await settled(page);

        await expect(page).toHaveScreenshot('booking-picker-375.png', { fullPage: true });
    });
});
