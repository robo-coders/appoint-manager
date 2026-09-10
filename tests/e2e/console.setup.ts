import { expect, test as setup } from '@playwright/test';
import { CONSOLE, CONSOLE_STATE } from './support';

/**
 * Sign in to the console once, for the whole run.
 *
 * `RateLimiter::for('admin-login')` allows **three attempts a minute** per email
 * and IP — tighter than the operator limiter, deliberately, because this is the
 * door to every tenant on the platform. Three console specs signing in for
 * themselves is three logins in about ten seconds, and the moment one of them
 * retries the fourth is throttled: the page stays on the form and the spec fails
 * sixty seconds later as a navigation timeout that reads like a broken redirect.
 *
 * This is the same fix, and the same reasoning, as `auth.setup.ts`. It is worth
 * writing down twice because the failure looks nothing like a rate limit.
 */
setup('authenticate as the super admin', async ({ page }) => {
    await page.goto('/admin/login');

    /*
     * The form, not the headline.
     *
     * This waited on a heading named "Console", which the console login has not
     * had since it was redesigned — "Console" is the `<title>` now and the `<h1>`
     * is a sentence. So this setup failed, and because all four console specs
     * depend on it, the whole super-admin surface silently stopped being tested:
     * "1 failed, 4 did not run". Waiting on the field this setup is about to
     * fill is both the thing it actually needs and one that does not move when
     * the copy does.
     */
    await expect(page.locator('input[type="email"]')).toBeVisible();

    await page.locator('input[type="email"]').fill(CONSOLE.email);
    await page.locator('input[type="password"]').fill(CONSOLE.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/admin\/?$/);

    await page.context().storageState({ path: CONSOLE_STATE });
});
