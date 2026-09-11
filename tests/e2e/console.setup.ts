import { expect, test as setup } from '@playwright/test';
import { CONSOLE, CONSOLE_STATE } from './support';

setup('authenticate as the super admin', async ({ page }) => {
    await page.goto('/admin/login');

    await expect(page.locator('input[type="email"]')).toBeVisible();

    await page.locator('input[type="email"]').fill(CONSOLE.email);
    await page.locator('input[type="password"]').fill(CONSOLE.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/admin\/?$/);

    await page.context().storageState({ path: CONSOLE_STATE });
});
