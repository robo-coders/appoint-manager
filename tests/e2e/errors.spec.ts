import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { expectSurface } from './support';

/**
 * The six error pages, at the three widths.
 *
 * Signed out, in the `public` project, because five of the six are reached by
 * people who are not signed in and the sixth reads the same either way.
 *
 * They are opened through `/dev/errors/{status}` — a route registered outside
 * production only, exactly like `/dev/components`. It `abort()`s rather than
 * rendering a view, so what is photographed is the whole real path: the
 * exception handler, `renderHttpException`, the namespaced `errors::{status}`
 * lookup and the view composer. A preview that rendered the template directly
 * would have shown a perfect page while every browser got the stock one, which
 * is not a hypothetical — the composer was registered for `errors.*` only,
 * which matches by hand and never through the handler.
 */
const STATUSES = [403, 404, 419, 429, 500, 503] as const;
const WIDTHS = [375, 768, 1280] as const;

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function openError(page: Page, status: number, width: number): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width, height: width === 375 ? 900 : 1000 });
    await page.goto(`/dev/errors/${status}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await settled(page);
}

for (const status of STATUSES) {
    for (const width of WIDTHS) {
        test(`${status} at ${width}`, async ({ page }) => {
            await openError(page, status, width);

            await expect(page).toHaveScreenshot(`error-${status}-${width}.png`, { fullPage: true });
        });
    }
}

/*
 * The rules the pictures cannot police.
 *
 * `--paper` and `--white` are a YIQ delta of 8.3 apart, which is under every
 * per-pixel threshold that still tolerates font rasterisation elsewhere — so a
 * surface change is invisible to a snapshot. See `playwright.config.ts`.
 */
test('every error page is on paper and carries no build asset', async ({ page }) => {
    for (const status of STATUSES) {
        await openError(page, status, 1280);

        await expectSurface(page.locator('body'), 'paper', `the ${status} page`);

        /*
         * No stylesheet, no script, no hashed asset. A 503 is served while the
         * Vite manifest is mid-replacement during a deploy, so an error page
         * that links a build artefact is a page that 500s at the one moment it
         * is the only page left. The other five inherit the same rule because
         * one shell serves all six.
         */
        const external = await page.evaluate(() => ({
            stylesheets: document.querySelectorAll('link[rel="stylesheet"]').length,
            scripts: document.querySelectorAll('script').length,
        }));

        expect(external.stylesheets, `${status} links a stylesheet`).toBe(0);
        expect(external.scripts, `${status} loads a script`).toBe(0);

        // And nothing scrolls sideways at any width.
        await page.setViewportSize({ width: 375, height: 900 });
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            `${status} scrolls sideways at 375`,
        ).toBe(true);
    }
});

/*
 * The customer's 404 is a different page from the operator's, and this is the
 * one width the brief singled out. Reached for real — `/book/{slug}` with a
 * slug that is not a salon — rather than through the preview route, because
 * that is exactly how a customer arrives at it.
 */
test('the customer’s 404 at 375 is not the operator’s', async ({ page }) => {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/book/no-such-salon');

    await expect(page.getByRole('heading', { name: /booking link/ })).toBeVisible();

    // No way back into our product: they are a customer holding a bad link, not
    // somebody shopping for appointment software.
    await expect(page.getByRole('link')).toHaveCount(0);

    await settled(page);
    await expect(page).toHaveScreenshot('error-404-customer-375.png', { fullPage: true });
});

/*
 * 419 is the one that matters most: an operator whose session went stale
 * mid-shift used to land in a stock page with no way out at all.
 */
test('419 offers a way back, not a dead end', async ({ page }) => {
    await openError(page, 419, 1280);

    const back = page.getByRole('link', { name: /Sign in and carry on/ });
    await expect(back).toBeVisible();
    // The promise is on the control, not only in the paragraph above it.
    await expect(back).toContainText('land back on the page you were on');
    await expect(page.getByText('puts you back where you were')).toBeVisible();
});
