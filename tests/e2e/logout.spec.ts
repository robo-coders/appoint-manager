import { expect, test } from '@playwright/test';
import { openAccountMenu, signIn } from './support';

/*
 * These sign in for themselves rather than reusing the shared session, because
 * they end it — a spec that logs out and leaves the saved state invalid is a
 * spec that breaks whatever runs next. Two logins, which is inside the
 * five-a-minute limiter with room to spare.
 */
test.use({ storageState: { cookies: [], origins: [] } });

/**
 * Logging out, and closing an account.
 *
 * The bug this covers was: `redirect('/')` from an Inertia visit is *followed*
 * by the Inertia client, which then receives a Blade document it has no page
 * component for and paints it inside the authenticated shell — the tenant rail
 * stayed on screen behind the marketing page, and only a browser refresh
 * escaped it.
 *
 * The Pest suite asserts the response type (409 plus `X-Inertia-Location`),
 * which is the mechanism. This asserts the outcome: after clicking Log out, the
 * marketing page is a real document and the rail is *gone*. A status assertion
 * could never see the rail.
 */
test.describe('logging out', () => {
    test('lands on the marketing page as a real document, with the shell gone', async ({ page }) => {
        await signIn(page);

        // The shell is there to start with, or the rest proves nothing.
        const rail = page.getByRole('complementary', { name: 'Sidebar' });
        await expect(rail).toBeVisible();

        await openAccountMenu(page);

        /*
         * A real navigation, not an Inertia swap. `waitForNavigation` on the
         * document is the thing the bug failed to do: the broken version never
         * left the page it was on.
         */
        await Promise.all([
            page.waitForURL((url) => !url.pathname.startsWith('/diary') && !url.pathname.startsWith('/dashboard')),
            page.getByRole('menuitem', { name: 'Log out' }).click(),
        ]);

        // The rail is gone — not hidden behind something, gone from the document.
        await expect(rail).toHaveCount(0);

        // And this is a document the browser navigated to, so it has its own
        // history entry and its own title.
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

        // Going back to the diary must reach the login form, not the diary.
        await page.goto('/diary');
        await expect(page).toHaveURL(/\/login/);
    });
});

/**
 * `ProfileController::destroy` had the identical bug, and the identical fix. It
 * is not driven end to end here — the account it closes is the demo owner, and
 * every other spec in this suite needs to be able to sign in as them. The Pest
 * suite asserts the response type for it instead
 * (`AuthenticationTest`: "closing an account forces a full page load too").
 *
 * What *is* worth checking here is that the control is reachable and says what
 * it does, because a destructive action hidden behind an unlabelled button is
 * its own problem.
 */
test('closing an account is reachable, labelled, and asks first', async ({ page }) => {
    await signIn(page);
    await page.goto('/profile');

    await expect(page.getByRole('heading', { name: 'Close your account' })).toBeVisible();

    const close = page.getByRole('button', { name: /close.*account|delete/i }).last();
    await close.click();

    // A confirmation, and it wants the password — not a single click away from
    // deleting a business.
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel(/password/i)).toBeVisible();
});
