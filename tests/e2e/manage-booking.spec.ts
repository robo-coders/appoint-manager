import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

const PHONE = { width: 375, height: 900 };

async function freezeTime(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
}

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function liveToken(page: Page): Promise<string> {
    await page.goto('/bookings?status=confirmed');
    await page.locator('table[aria-label="Bookings"] tbody tr a').first().click();
    await expect(page.getByRole('link', { name: '← Bookings' })).toBeVisible();

    await page.goto(page.url());

    const token = await page.evaluate(() => {
        const root = document.getElementById('app');
        const payload = root ? JSON.parse(root.dataset.page ?? '{}') : {};

        return payload?.props?.booking?.public_token ?? '';
    });

    expect(token).toMatch(/^[0-9a-f-]{36}$/);

    return token;
}

test.describe('a link that is no longer active', () => {
    test('is a calm sentence, not a framework error', async ({ page }) => {
        await freezeTime(page);
        await page.setViewportSize(PHONE);
        await page.goto('/book/b/00000000-0000-4000-8000-000000000000');
        await settled(page);

        await expect(page.getByText('This booking link is no longer active')).toBeVisible();
        await expect(page.getByText('There is nothing at this address')).toHaveCount(0);

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await expect(page).toHaveScreenshot('manage-inactive-375.png', { fullPage: true });
    });

    test('answers a token of the wrong shape the same way', async ({ page }) => {
        await page.goto('/book/b/abc');

        await expect(page.getByText('This booking link is no longer active')).toBeVisible();
    });
});

test.describe('a live link', () => {
    test('states the appointment and carries the mockup footer', async ({ page }) => {
        const token = await liveToken(page);

        await freezeTime(page);
        await page.setViewportSize(PHONE);
        await page.goto(`/book/b/${token}`);
        await settled(page);

        await expect(page.locator('h1.text-34')).toBeVisible();

        await expect(page.getByText(/Ref [0-9a-f]{8}/)).toBeVisible();

        const message = page.getByRole('link', { name: 'Message the salon' });

        if (await message.count()) {
            await expect(message).toHaveAttribute('href', /^sms:/);
        }

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await expect(page).toHaveScreenshot('manage-live-375.png', { fullPage: true });
    });

    test('opens the picker on the same engine the booking page uses', async ({ page }) => {
        const token = await liveToken(page);

        await freezeTime(page);
        await page.setViewportSize(PHONE);
        await page.goto(`/book/b/${token}`);
        await settled(page);

        await page.getByRole('button', { name: /Move this appointment/ }).click();

        await expect(
            page.locator('[role="group"][aria-label="Morning times"], [role="group"][aria-label="Afternoon times"]')
                .first()
                .or(page.getByText(/No availability in the next/))
                .or(page.getByText('Closed this day')),
        ).toBeVisible();
    });
});
