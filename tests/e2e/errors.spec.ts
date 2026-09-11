import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { expectSurface } from './support';

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

test('every error page is on paper and carries no build asset', async ({ page }) => {
    for (const status of STATUSES) {
        await openError(page, status, 1280);

        await expectSurface(page.locator('body'), 'paper', `the ${status} page`);

        const external = await page.evaluate(() => ({
            stylesheets: document.querySelectorAll('link[rel="stylesheet"]').length,
            scripts: document.querySelectorAll('script').length,
        }));

        expect(external.stylesheets, `${status} links a stylesheet`).toBe(0);
        expect(external.scripts, `${status} loads a script`).toBe(0);

        await page.setViewportSize({ width: 375, height: 900 });
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            `${status} scrolls sideways at 375`,
        ).toBe(true);
    }
});

test('the customer’s 404 at 375 is not the operator’s', async ({ page }) => {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/book/no-such-salon');

    await expect(page.getByRole('heading', { name: /booking link/ })).toBeVisible();

    await expect(page.getByRole('link')).toHaveCount(0);

    await settled(page);
    await expect(page).toHaveScreenshot('error-404-customer-375.png', { fullPage: true });
});

test('419 offers a way back, not a dead end', async ({ page }) => {
    await openError(page, 419, 1280);

    const back = page.getByRole('link', { name: /Sign in and carry on/ });
    await expect(back).toBeVisible();
    await expect(back).toContainText('land back on the page you were on');
    await expect(page.getByText('puts you back where you were')).toBeVisible();
});
