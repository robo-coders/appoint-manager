import { test as setup } from '@playwright/test';
import { AUTH_STATE, signIn } from './support';

setup('authenticate as the salon owner', async ({ page }) => {
    await signIn(page);
    await page.context().storageState({ path: AUTH_STATE });
});
