import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

const PHONE = { width: 375, height: 900 };

const SHEET = '[role="dialog"]';

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

async function openPhone(page: Page, url: string): Promise<void> {
    await freezeTime(page);
    await page.setViewportSize(PHONE);
    await page.goto(url);
    await settled(page);
}

const tabBar = (page: Page) => page.locator('nav[aria-label="Main"].bottom-0');

const tabCells = (page: Page) => tabBar(page).locator('a, button');

async function noSidewaysScroll(page: Page): Promise<void> {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

async function clearsTheTabBar(page: Page, lastRow: string): Promise<void> {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(100);

    const row = await page.locator(lastRow).last().boundingBox();
    const bar = await tabBar(page).boundingBox();

    expect(row).not.toBeNull();
    expect(bar).not.toBeNull();
    expect(row!.y + row!.height).toBeLessThanOrEqual(bar!.y + 1);
}

test.describe('the shell swaps for the rail', () => {
    test('replaces the rail with five destinations and no hamburger', async ({ page }) => {
        await openPhone(page, '/bookings');

        await expect(page.locator('aside[aria-label="Sidebar"]')).toBeHidden();
        await expect(page.getByRole('button', { name: 'Menu' })).toHaveCount(0);

        await expect(tabBar(page)).toBeVisible();
        await expect(tabCells(page)).toHaveCount(5);
        await expect(tabCells(page)).toContainText(['Bookings', 'Waitlist', 'Overview', 'Staff', 'More']);
    });

    test('marks the tab for the screen it is on, and only that one', async ({ page }) => {
        await openPhone(page, '/waitlist');

        await expect(tabBar(page).locator('[aria-current="page"]')).toHaveCount(1);
        await expect(tabBar(page).locator('[aria-current="page"]')).toContainText('Waitlist');
    });

    test('keeps the rail and drops the tab bar again above the breakpoint', async ({ page }) => {
        await openPhone(page, '/bookings');
        await page.setViewportSize({ width: 1280, height: 1000 });
        await page.waitForTimeout(100);

        await expect(page.locator('aside[aria-label="Sidebar"]')).toBeVisible();
        await expect(tabBar(page)).toBeHidden();
    });

    test('follows a rotation mid-session with no reload', async ({ page }) => {
        await openPhone(page, '/bookings');
        await expect(tabBar(page)).toBeVisible();

        await page.setViewportSize({ width: 900, height: 375 });
        await page.waitForTimeout(100);
        await expect(tabBar(page)).toBeHidden();
        await expect(page.locator('aside[aria-label="Sidebar"]')).toBeVisible();

        await page.setViewportSize(PHONE);
        await page.waitForTimeout(100);
        await expect(tabBar(page)).toBeVisible();
    });

    test('reaches everything the rail had through More, and closes three ways', async ({ page }) => {
        await openPhone(page, '/bookings');

        const open = async () => {
            await tabBar(page).getByRole('button', { name: /More/ }).click();
            await expect(page.locator(SHEET)).toBeVisible();
        };

        await open();
        for (const label of ['Diary', 'Overdue', 'Customers', 'Services', 'Hours', 'Time off', 'Settings']) {
            await expect(page.locator(SHEET).getByRole('link', { name: label })).toBeVisible();
        }

        await page.locator(SHEET).getByRole('button', { name: 'Close' }).click();
        await expect(page.locator(SHEET)).toBeHidden();

        await open();
        await page.keyboard.press('Escape');
        await expect(page.locator(SHEET)).toBeHidden();

        await open();
        await page.locator('.bg-overlay').click({ position: { x: 5, y: 5 } });
        await expect(page.locator(SHEET)).toBeHidden();
    });

    test('navigates from a tab', async ({ page }) => {
        await openPhone(page, '/bookings');
        await tabBar(page).getByRole('link', { name: /Waitlist/ }).click();

        await expect(page.getByRole('heading', { name: 'Waitlist' })).toBeVisible();
        await expect(tabBar(page).locator('[aria-current="page"]')).toContainText('Waitlist');
    });
});

test.describe('the five screens at 375', () => {
    test('bookings is a list led by the time, not a squeezed table', async ({ page }) => {
        await openPhone(page, '/bookings');
        await expect(page.getByRole('heading', { name: 'Bookings' })).toBeVisible();

        await expect(page.locator('table[aria-label="Bookings"]')).toBeHidden();

        const first = page.locator('ul[aria-label="Bookings"] > li').first();
        await expect(first).toBeVisible();
        await expect(first.locator('.w-col-time')).toBeVisible();

        await noSidewaysScroll(page);
        await clearsTheTabBar(page, 'ul[aria-label="Bookings"] > li');
        await expect(page).toHaveScreenshot('mobile-bookings-375.png', { mask: volatileRegions(page) });
    });

    test('the waitlist keeps its queue positions', async ({ page }) => {
        await openPhone(page, '/waitlist');
        await expect(page.getByRole('heading', { name: 'Waitlist' })).toBeVisible();

        await expect(page.locator('table[aria-label="Waitlist"]')).toBeHidden();
        await expect(page.locator('ul[aria-label="Waitlist"] > li').first().locator('.w-col-time')).toBeVisible();

        await noSidewaysScroll(page);
        await clearsTheTabBar(page, 'ul[aria-label="Waitlist"] > li');
        await expect(page).toHaveScreenshot('mobile-waitlist-375.png', { mask: volatileRegions(page) });
    });

    test('the overview stacks to one column', async ({ page }) => {
        await openPhone(page, '/dashboard');
        await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();

        await noSidewaysScroll(page);
        await expect(page).toHaveScreenshot('mobile-overview-375.png', { mask: volatileRegions(page) });
    });

    test('the staff list stacks, and invite is a full-width control under it', async ({ page }) => {
        await openPhone(page, '/staff');
        await expect(page.getByRole('heading', { name: 'Staff' })).toBeVisible();

        const invite = page.getByRole('button', { name: /Invite a team member/ });
        await expect(invite).toBeVisible();

        const box = await invite.boundingBox();
        const lastRow = await page.locator('main li').last().boundingBox();
        expect(box!.width).toBeGreaterThan(PHONE.width * 0.8);
        expect(box!.y).toBeGreaterThanOrEqual(lastRow!.y + lastRow!.height - 1);

        await noSidewaysScroll(page);
        await expect(page).toHaveScreenshot('mobile-staff-375.png', { mask: volatileRegions(page) });
    });

    test('the booking record pins its actions above the tab bar', async ({ page }) => {
        await openPhone(page, '/bookings');
        await page.locator('ul[aria-label="Bookings"] > li a').first().click();
        await settled(page);

        await expect(page.getByRole('link', { name: '← Bookings' })).toBeVisible();

        const actions = page.locator('.above-tabbar');
        await expect(actions).toBeVisible();

        const actionBox = await actions.boundingBox();
        const barBox = await tabBar(page).boundingBox();
        expect(actionBox!.y + actionBox!.height).toBeLessThanOrEqual(barBox!.y + 1);

        await expect(page.getByRole('button', { name: 'Show in the diary' })).toBeHidden();

        await noSidewaysScroll(page);
        await expect(page).toHaveScreenshot('mobile-booking-detail-375.png', { mask: volatileRegions(page) });
    });

    test('fits all three actions on a started appointment', async ({ page }) => {
        await openPhone(page, '/bookings/182');

        const actions = page.locator('.above-tabbar');
        await expect(actions).toBeVisible();
        await expect(actions.getByRole('button')).toHaveCount(3);

        const bar = await actions.boundingBox();
        const tabs = await tabBar(page).boundingBox();

        expect(bar!.height).toBeLessThan(80);
        expect(bar!.y + bar!.height).toBeLessThanOrEqual(tabs!.y + 1);

        await noSidewaysScroll(page);
        await expect(page).toHaveScreenshot('mobile-booking-detail-actions-375.png', {
            mask: volatileRegions(page),
        });
    });
});

const VISUAL_CHECK = '.design/mockups/Backend/visual-check';

const COMPARISONS = [
    { file: '12-mobile-bookings-app.png', url: '/bookings', heading: 'Bookings' },
    { file: '13-mobile-waitlist-app.png', url: '/waitlist', heading: 'Waitlist' },
    { file: '14-mobile-overview-app.png', url: '/dashboard', heading: 'Overview' },
    { file: '15-mobile-staff-app.png', url: '/staff', heading: 'Staff' },
] as const;

test.describe('visual check artefacts', () => {
    for (const shot of COMPARISONS) {
        test(`captures ${shot.file}`, async ({ page }) => {
            await openPhone(page, shot.url);
            await expect(page.getByRole('heading', { name: shot.heading })).toBeVisible();
            await page.screenshot({ path: `${VISUAL_CHECK}/${shot.file}`, fullPage: true });
        });
    }

    test('captures 16-mobile-booking-detail-app.png', async ({ page }) => {
        await openPhone(page, '/bookings');
        await page.locator('ul[aria-label="Bookings"] > li a').first().click();
        await settled(page);
        await page.screenshot({ path: `${VISUAL_CHECK}/16-mobile-booking-detail-app.png`, fullPage: true });
    });
});
