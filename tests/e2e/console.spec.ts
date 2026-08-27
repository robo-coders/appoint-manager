import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

/**
 * The super admin console, at 375.
 *
 * Its own project with its own session, because the console has its own session
 * on its own host and the operator storage state is not it — presenting an app
 * cookie here would be exactly the thing the surface split exists to prevent.
 *
 * 375 because the brief asked for it and because a dense console is where a
 * narrow width actually costs something: six columns, four of them `secondary`,
 * and a row action menu that has to stay reachable. The wide table is the same
 * `ui/Table` the bookings snapshots already exercise at 768 and 1280.
 */
async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

/**
 * The console session comes from `console.setup.ts`, which signs in once.
 * `admin-login` allows three attempts a minute; a spec that signs in for itself
 * is the fourth one in the run, throttled, and failing as a timeout.
 */
async function openConsole(page: Page, path = '/admin'): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 375, height: 1200 });
    await page.goto(path);
}

/*
 * The door itself is signed out, so it clears the stored session first — this
 * project carries one, and going to `/admin/login` with a valid console session
 * is a redirect straight back out.
 */
test('the console door at 375', async ({ page, context }) => {
    await context.clearCookies();
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 375, height: 900 });
    await page.goto('/admin/login');
    await expect(page.getByRole('heading', { name: 'Console' })).toBeVisible();
    await settled(page);

    await expect(page).toHaveScreenshot('console-login-375.png', { fullPage: true });
});

test('the tenant list at 375', async ({ page }) => {
    await openConsole(page);

    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();

    /*
     * The density is the surface's, set once on the body. `tokens.css` has
     * carried this block since the density pass and nothing ever set it, so the
     * console rendered at operator density until phase 9.
     */
    await expect(page.locator('body')).toHaveAttribute('data-density', 'console');

    // Trouble first. The screen opens sorted on it, not on name — a hundred
    // salons in alphabetical order is a directory, not a console.
    await expect(page.getByRole('alert').or(page.getByText(/needs? looking at/))).toBeVisible();

    await settled(page);
    await expect(page).toHaveScreenshot('console-tenants-375.png', { fullPage: true });
});

/*
 * The dangerous action, and the state that most often ships unconsidered on a
 * screen like this. It must name the salon *and* the person, because the table
 * it was opened from has a row per salon and they all look alike.
 */
test('impersonation says whose session it is about to borrow', async ({ page }) => {
    await openConsole(page);

    /*
     * By the menu's accessible name, not by finding a `row` first. At 375
     * `ui/Table` is a list of `<li>`s rather than a `<table>`, so `getByRole
     * ('row')` matches nothing here — and `rowLabel` exists precisely so each
     * row's menu says which row it belongs to.
     */
    await page.getByRole('button', { name: 'Actions for Bramble and Co' }).click();
    await page.getByRole('menuitem', { name: /Sign in as the owner/ }).click();

    const dialog = page.getByRole('alertdialog');
    await expect(dialog).toBeVisible();
    // The menu it was opened from is gone. Two overlapping surfaces on a
    // confirm that exists to be read carefully is not a confirm.
    await expect(page.getByRole('menu')).toBeHidden();
    await expect(dialog).toContainText('Bramble and Co');
    await expect(dialog).toContainText('Ines Duarte');
    // The button says what it is about to do, not "Confirm".
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

    /*
     * The exception has to survive to 375. This screen used to
     * `JSON.stringify` the whole `failed_jobs` row into a `<pre>`, and the first
     * narrow layout dropped the exception entirely and showed the job name and a
     * timestamp — which is everything except the reason you opened it.
     *
     * The demo seed writes failures on purpose, so the empty state is asserted
     * where it can be: `ConsoleScreenTest` covers a console with none.
     */
    await expect(page.getByText(/RuntimeException|Exception|Error/).first()).toBeVisible();
    // Long exception class names must wrap, not push the page sideways.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await settled(page);
    await expect(page).toHaveScreenshot('console-failures-375.png', { fullPage: true });
});
