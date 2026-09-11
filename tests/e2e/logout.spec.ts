import { expect, test } from '@playwright/test';
import { openAccountMenu, signIn } from './support';

test.use({ storageState: { cookies: [], origins: [] } });

test.describe('logging out', () => {
    test('lands on the marketing page as a real document, with the shell gone', async ({ page }) => {
        await signIn(page);

        const rail = page.getByRole('complementary', { name: 'Sidebar' });
        await expect(rail).toBeVisible();

        await openAccountMenu(page);

        await Promise.all([
            page.waitForURL((url) => !url.pathname.startsWith('/diary') && !url.pathname.startsWith('/dashboard')),
            page.getByRole('menuitem', { name: 'Log out' }).click(),
        ]);

        await expect(rail).toHaveCount(0);

        await expect(page).toHaveURL(/\/$/);
        await expect(page.locator('#app')).toHaveCount(0);
    });

    test('the session is really over, not just the page', async ({ page }) => {
        await signIn(page);

        await openAccountMenu(page);
        await Promise.all([
            page.waitForURL((url) => !url.pathname.startsWith('/diary')),
            page.getByRole('menuitem', { name: 'Log out' }).click(),
        ]);

        await page.goto('/diary');
        await expect(page).toHaveURL(/\/login/);
    });
});

test('closing an account is reachable, labelled, and asks first', async ({ page }) => {
    await signIn(page);
    await page.goto('/profile');

    await expect(page.getByRole('heading', { name: 'Close your account' })).toBeVisible();

    const close = page.getByRole('button', { name: /close.*account|delete/i }).last();
    await close.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel(/password/i)).toBeVisible();
});
