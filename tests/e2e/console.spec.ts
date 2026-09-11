import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function openConsole(page: Page, path = '/admin'): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 375, height: 1200 });
    await page.goto(path);
}

test('the console door at 375', async ({ page, context }) => {
    await context.clearCookies();
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/admin/login');
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await settled(page);

    await expect(page).toHaveScreenshot('console-login-375.png', { fullPage: true });
});

test('the tenant list at 375', async ({ page }) => {
    await openConsole(page);

    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();

    await expect(page.locator('body')).toHaveAttribute('data-density', 'console');

    await expect(page.getByRole('alert').or(page.getByText(/needs? looking at/))).toBeVisible();

    await settled(page);
    await expect(page).toHaveScreenshot('console-tenants-375.png', { fullPage: true });
});

test('impersonation says whose session it is about to borrow', async ({ page }) => {
    await openConsole(page);

    await page.getByRole('button', { name: 'Actions for Bramble and Co' }).click();
    await page.getByRole('menuitem', { name: /Sign in as the owner/ }).click();

    const dialog = page.getByRole('alertdialog');
    await expect(dialog).toBeVisible();
    await expect(page.getByRole('menu')).toBeHidden();
    await expect(dialog).toContainText('Bramble and Co');
    await expect(dialog).toContainText('Ines Duarte');
    await expect(dialog.getByRole('button', { name: 'Sign in as Ines Duarte' })).toBeVisible();

    await settled(page);
    await expect(page).toHaveScreenshot('console-impersonate-375.png');
});

test('the send log and the failures screen at 375', async ({ page }) => {
    await openConsole(page, '/admin/messages');

    await expect(page.getByRole('heading', { name: 'Send log' })).toBeVisible();
    await settled(page);
    await expect(page).toHaveScreenshot('console-messages-375.png', { fullPage: true });

    await page.goto('/admin/failures');
    await expect(page.getByRole('heading', { name: 'Failures' })).toBeVisible();

    await expect(page.getByText(/RuntimeException|Exception|Error/).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await settled(page);
    await expect(page).toHaveScreenshot('console-failures-375.png', { fullPage: true });
});
