import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import path from 'node:path';

const OUT = path.resolve('.design/mockups/Backend/visual-check');

const PAGES = [
    { name: 'diary', url: '/diary?date=2026-08-26', heading: /August/ },
    { name: 'bookings', url: '/bookings', heading: /Bookings/ },
    { name: 'settings', url: '/settings', heading: /Settings/ },
] as const;

async function freezeTime(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
}

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function loadAs(page: Page, theme: 'light' | 'dark', url: string, heading: RegExp): Promise<void> {
    await freezeTime(page);
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.addInitScript((value) => {
        localStorage.setItem('diarydesk.appearance', value);
    }, theme);
    await page.goto(url);
    await expect(page.getByRole('heading', { name: heading }).first()).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    await settled(page);
}

test.describe('operator appearance visual check', () => {
    for (const screen of PAGES) {
        for (const theme of ['light', 'dark'] as const) {
            test(`${screen.name} ${theme}`, async ({ page }) => {
                await loadAs(page, theme, screen.url, screen.heading);
                await page.screenshot({
                    path: path.join(OUT, `${screen.name}-${theme}-app.png`),
                    fullPage: true,
                });
            });
        }
    }
});
