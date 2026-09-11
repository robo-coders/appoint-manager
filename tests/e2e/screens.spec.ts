import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { DEMO } from './support';

const WIDTHS = [
    { name: '375', width: 375, height: 900 },
    { name: '768', width: 768, height: 1000 },
    { name: '1280', width: 1280, height: 1000 },
] as const;

async function freezeTime(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
}

function volatileRegions(page: Page) {
    return [page.locator('.pl-sub-indent'), page.locator('[role="timer"]')];
}

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
            await expect(page.getByRole('heading', { name: 'Today’s diary' })).toBeVisible();
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

            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

            await expect(page).toHaveScreenshot(`bookings-${size.name}.png`, { mask: volatileRegions(page) });
        });
    }
});

test.describe('the row-actions menu', () => {
    for (const screen of [
        { name: 'time-off', path: '/time-off', heading: 'Time off' },
        { name: 'overdue', path: '/overdue', heading: /^Overdue/ },
    ] as const) {
        test(`leaves the row readable with the menu open on ${screen.name}`, async ({ page }) => {
            await freezeTime(page);
            await page.setViewportSize({ width: 1280, height: 1000 });
            await page.goto(screen.path);
            await expect(page.getByRole('heading', { name: screen.heading })).toBeVisible();
            await settled(page);

            const row = page.locator('table tbody tr').first();
            await expect(row).toBeVisible();

            const cells = row.locator('td');
            const before = await cells.allInnerTexts();
            expect(before.filter((text) => text.trim() !== '').length).toBeGreaterThan(1);

            await row.locator('button[aria-haspopup="menu"]').click();

            const panel = page.locator('body > [role="menu"]');
            await expect(panel).toBeVisible();

            expect(await cells.allInnerTexts()).toEqual(before);

            for (let index = 0; index < before.length; index++) {
                if (before[index].trim() !== '') await expect(cells.nth(index)).toBeVisible();
            }

            expect(await panel.evaluate((el) => el.closest('.overflow-x-auto') !== null)).toBe(false);
            expect(await panel.evaluate((el) => el.parentElement === document.body)).toBe(true);

            const overflow = await panel.evaluate((el) => {
                const box = el.getBoundingClientRect();

                return {
                    below: box.bottom - window.innerHeight,
                    right: box.right - window.innerWidth,
                };
            });
            expect(overflow.below).toBeLessThanOrEqual(0);
            expect(overflow.right).toBeLessThanOrEqual(0);

            const actions = panel.locator('[role="menuitem"]');
            expect(await actions.count()).toBeGreaterThan(0);
            await expect(actions.last()).toBeVisible();

            await expect(page).toHaveScreenshot(`row-menu-${screen.name}.png`, { mask: volatileRegions(page) });

            await page.keyboard.press('Escape');
            await expect(panel).toBeHidden();
            await expect(row.locator('button[aria-haspopup="menu"]')).toHaveAttribute('aria-expanded', 'false');
        });
    }
});

test('customers at 375, where it is a list and not a table', async ({ page }) => {
    await freezeTime(page);
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/customers');
    await expect(page.getByRole('heading', { name: 'Customers' })).toBeVisible();
    await settled(page);

    const first = page.locator('ul[aria-label="Customers"] > li').first();
    await expect(first).toBeVisible();
    await expect(first.locator('a[href^="tel:"]')).toBeVisible();
    await expect(page.locator('table[aria-label="Customers"]')).toBeHidden();
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
