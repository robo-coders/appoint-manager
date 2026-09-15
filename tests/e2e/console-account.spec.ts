import { expect, test, type Page } from '@playwright/test';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { FROZEN_NOW } from '../../playwright.config';
import { CONSOLE } from './support';

const VISUAL_CHECK = '.design/mockups/Backend/visual-check';
const MOCKUP = pathToFileURL(resolve('.design/mockups/admin/Admin Dashboard Mockups.dc.html')).href;

async function openConsole(page: Page, path = '/admin'): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(path);
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
}

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

const trigger = (page: Page) => page.getByTestId('account-menu-trigger');
const menu = (page: Page) => page.getByTestId('account-menu');

const decode = (value: string): string =>
    value
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');

const encode = (value: string): string => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;');

/*
 * The seeded console admin has a confirmed address, so the notice it is gated
 * on never fires against the real page. Rewriting the Inertia payload on the
 * way in is how this repo drives an edge state — it leaves the seed alone, and
 * the seed is what every screenshot baseline is measured against.
 */
async function openConsoleUnverified(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 1280, height: 900 });

    await page.route(
        '**/admin',
        async (route) => {
            const response = await route.fetch();
            const html = await response.text();
            const encoded = html.match(/data-page="([^"]*)"/)?.[1];

            if (encoded === undefined) {
                await route.fulfill({ response, body: html });

                return;
            }

            const payload = JSON.parse(decode(encoded));
            payload.props.auth.user.email_verified_at = null;
            /*
             * And the dismissals, emptied. Dismissing is session-scoped by
             * design, and every console spec replays one stored cookie — so a
             * test that dismisses the notice hides it from every test that runs
             * after it. Resetting here keeps these three independent of order.
             */
            payload.props.dismissedNotices = [];

            await route.fulfill({ response, body: html.replace(encoded, encode(JSON.stringify(payload))) });
        },
        { times: 1 },
    );

    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
}

test.describe('the unconfirmed-email notice', () => {
    test('offers a resend and a dismiss', async ({ page }) => {
        await openConsoleUnverified(page);

        const banner = page.getByTestId('banner');

        await expect(banner).toBeVisible();
        await expect(banner).toContainText('Confirm your email');
        await expect(banner.getByRole('button', { name: 'Resend the email' })).toBeVisible();
        await expect(banner.getByTestId('banner-dismiss')).toBeVisible();
    });

    test('goes away when the dismiss lands', async ({ page }) => {
        await openConsoleUnverified(page);

        await page.getByTestId('banner-dismiss').click();

        await expect(page.getByTestId('banner')).toBeHidden();
    });

    test('stays put, and says so, when the dismiss does not land', async ({ page }) => {
        await openConsoleUnverified(page);

        await page.route('**/notices/**', (route) => route.abort('failed'));

        await page.getByTestId('banner-dismiss').click();

        await expect(page.getByTestId('toast')).toHaveAttribute('data-tone', 'error');
        await expect(page.getByTestId('banner')).toBeVisible();
    });
});

test.describe('the account menu', () => {
    test('opens from the identity row and names who is signed in', async ({ page }) => {
        await openConsole(page);

        await expect(menu(page)).toBeHidden();
        await trigger(page).click();

        await expect(menu(page)).toBeVisible();
        await expect(menu(page)).toContainText(CONSOLE.email);
        await expect(menu(page).getByRole('menuitem', { name: 'Profile' })).toBeVisible();
        await expect(menu(page).getByRole('menuitem', { name: 'Search' })).toBeVisible();
        await expect(menu(page).getByRole('menuitem', { name: 'Log out' })).toBeVisible();
    });

    test('carries the build in its footer and leaves the environment to the breadcrumb', async ({ page }) => {
        await openConsole(page);
        await trigger(page).click();

        await expect(menu(page)).toContainText('dev');
        await expect(menu(page)).not.toContainText(/local|testing|production/);
        await expect(page.getByTestId('console-breadcrumb')).toContainText(/local|testing|production/);
    });

    test('names who is signed in once, not twice', async ({ page }) => {
        await openConsole(page);

        const rail = page.getByRole('complementary', { name: 'Sidebar' });

        await expect(rail.getByText(CONSOLE.name, { exact: true })).toHaveCount(1);

        await trigger(page).click();
        await expect(menu(page)).toBeVisible();

        await expect(rail.getByText(CONSOLE.name, { exact: true })).toHaveCount(1);
        await expect(rail.getByTestId('account-menu-trigger')).toHaveCount(1);
        await expect(menu(page)).not.toContainText(CONSOLE.name);
    });

    test('closes on Escape, on an outside click, and on a route change', async ({ page }) => {
        await openConsole(page);

        await trigger(page).click();
        await expect(menu(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(menu(page)).toBeHidden();
        await expect(trigger(page)).toBeFocused();

        await trigger(page).click();
        await expect(menu(page)).toBeVisible();
        await page.getByRole('heading', { name: 'Tenants' }).click();
        await expect(menu(page)).toBeHidden();

        await trigger(page).click();
        await expect(menu(page)).toBeVisible();
        await page.getByRole('link', { name: 'Verticals' }).click();
        await expect(menu(page)).toBeHidden();
    });

    test('walks between its items on the arrow keys', async ({ page }) => {
        await openConsole(page);

        await trigger(page).focus();
        await page.keyboard.press('ArrowDown');

        await expect(menu(page).getByRole('menuitem', { name: 'Profile' })).toBeFocused();

        await page.keyboard.press('ArrowDown');
        await expect(menu(page).getByRole('menuitem', { name: 'Search' })).toBeFocused();

        await page.keyboard.press('ArrowUp');
        await expect(menu(page).getByRole('menuitem', { name: 'Profile' })).toBeFocused();
    });
});

test.describe('logging out of the console', () => {
    test('asks before it does it, and Stay is a no-op', async ({ page }) => {
        await openConsole(page);

        await trigger(page).click();
        await page.getByTestId('account-menu-logout').click();

        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText(CONSOLE.email);
        await expect(dialog.getByRole('button', { name: 'Log out' })).toBeVisible();

        await dialog.getByRole('button', { name: 'Stay' }).click();

        await expect(dialog).toBeHidden();
        await expect(page).toHaveURL(/\/admin$/);
        await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
    });

    test('Escape is Stay', async ({ page }) => {
        await openConsole(page);

        await trigger(page).click();
        await page.getByTestId('account-menu-logout').click();
        await expect(page.getByRole('alertdialog')).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(page.getByRole('alertdialog')).toBeHidden();
        await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
    });

    test('keeps the dialog open and says so when the logout never lands', async ({ page }) => {
        await openConsole(page);

        await page.route('**/admin/logout', (route) => route.abort('failed'));

        await trigger(page).click();
        await page.getByTestId('account-menu-logout').click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Log out' }).click();

        await expect(page.getByTestId('toast')).toBeVisible();
        await expect(page.getByTestId('toast')).toHaveAttribute('data-tone', 'error');
        await expect(page.getByRole('alertdialog')).toBeVisible();
        await expect(page).toHaveURL(/\/admin$/);
    });
});

/*
 * On its own session, and that is not tidiness.
 *
 * `Auth::logout()` invalidates the session server-side, and every other console
 * spec is replaying the one cookie `console.setup.ts` stored. A logout test
 * sharing that cookie signs the whole project out from under itself — which it
 * did, and read as nine unrelated specs landing on the login page.
 */
test.describe('ending the console session for real', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('logs out and lands on the console door', async ({ page }) => {
        await page.clock.setFixedTime(new Date(FROZEN_NOW));
        await page.setViewportSize({ width: 1280, height: 900 });

        await page.goto('/admin/login');
        await page.locator('input[type="email"]').fill(CONSOLE.email);
        await page.locator('input[type="password"]').fill(CONSOLE.password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL(/\/admin\/?$/);

        await trigger(page).click();
        await page.getByTestId('account-menu-logout').click();

        await Promise.all([
            page.waitForURL(/\/admin\/login/),
            page.getByRole('alertdialog').getByRole('button', { name: 'Log out' }).click(),
        ]);

        await expect(page.locator('input[type="email"]')).toBeVisible();

        await page.goto('/admin');
        await expect(page).toHaveURL(/\/admin\/login/);
    });
});

test.describe('the console shell', () => {
    test('groups the rail under Platform and counts what the screens hold', async ({ page }) => {
        await openConsole(page);

        const rail = page.getByRole('complementary', { name: 'Sidebar' });

        await expect(rail.getByText('Platform', { exact: true })).toBeVisible();
        await expect(rail.getByRole('link', { name: /Tenants/ })).toHaveAttribute('aria-current', 'page');

        const tenants = await page.getByRole('row').count();
        await expect(rail.getByRole('link', { name: /^Tenants/ })).toContainText(String(tenants - 1));
    });

    test('names where you are and which environment it is', async ({ page }) => {
        await openConsole(page);

        const crumb = page.getByTestId('console-breadcrumb');

        await expect(crumb).toContainText('Platform');
        await expect(crumb).toContainText('Tenants');
        await expect(crumb).toContainText(/local|testing|production/);
    });

    test('gives every rail item its leading icon', async ({ page }) => {
        await openConsole(page);

        const rail = page.getByRole('complementary', { name: 'Sidebar' });

        for (const label of ['Tenants', 'Send log', 'Failures', 'Verticals']) {
            await expect(rail.getByRole('link', { name: new RegExp(`^${label}`) }).locator('svg')).toHaveCount(1);
        }

        await expect(rail.getByRole('button', { name: /Search/ }).locator('svg')).toHaveCount(1);
    });

    test('sets the stat labels in small caps rather than shouting them', async ({ page }) => {
        await openConsole(page);

        const label = page.locator('.eyebrow', { hasText: 'Live tenants' });

        await expect(label).toBeVisible();
        await expect(label).toHaveCSS('font-variant-caps', 'all-small-caps');
        await expect(label).toHaveCSS('text-transform', 'none');
    });

    test('leads each tenant row with an initial chip over its booking domain', async ({ page }) => {
        await openConsole(page);

        const row = page.getByRole('row').filter({ hasText: 'Paw & Order' }).first();

        await expect(row.getByText('PO', { exact: true })).toBeVisible();

        // The booking link as a person reads it: the host and the salon's path,
        // with the scheme taken off. Which host that is depends on the surface
        // config, so the assertion is "no scheme, and it names this salon".
        const domain = await row.locator('.font-mono').innerText();

        expect(domain).toContain('paw');
        expect(domain).not.toContain('http');
    });

    test('filters the existing table from the toolbar', async ({ page }) => {
        await openConsole(page);

        const before = await page.getByRole('row').count();

        await page.getByRole('textbox', { name: 'Search tenants' }).fill('zzz-no-such-salon');

        // The table paints its empty state twice — once for the columns, once for
        // the narrow list — and only one of the two is shown at any width.
        await expect(
            page.getByText('Nothing matches those filters').filter({ visible: true }),
        ).toBeVisible();
        expect(await page.getByRole('row').count()).toBeLessThan(before);
    });
});

test.describe('visual check artefacts', () => {
    test('captures 17-console-account-menu', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(MOCKUP);
        await settled(page);
        await page.locator('[id="1a"] .dv-card').screenshot({
            path: `${VISUAL_CHECK}/17-console-account-menu-mockup.png`,
        });

        await openConsole(page);
        await trigger(page).click();
        await expect(menu(page)).toBeVisible();
        await settled(page);
        await page.getByRole('complementary', { name: 'Sidebar' }).screenshot({
            path: `${VISUAL_CHECK}/17-console-account-menu-app.png`,
        });
    });

    test('captures 18-console-logout-dialog', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(MOCKUP);
        await settled(page);
        await page.locator('[id="1b"] .dv-card').screenshot({
            path: `${VISUAL_CHECK}/18-console-logout-dialog-mockup.png`,
        });

        await openConsole(page);
        await trigger(page).click();
        await page.getByTestId('account-menu-logout').click();
        await expect(page.getByRole('alertdialog')).toBeVisible();
        await settled(page);
        await page.screenshot({ path: `${VISUAL_CHECK}/18-console-logout-dialog-app.png` });
    });

    test('captures 20-console-shell-notice', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(MOCKUP);
        await settled(page);
        await page.locator('[id="1e"] .dv-card').screenshot({
            path: `${VISUAL_CHECK}/20-console-shell-notice-mockup.png`,
        });

        await openConsoleUnverified(page);
        await expect(page.getByTestId('banner')).toBeVisible();
        await settled(page);
        await page.screenshot({ path: `${VISUAL_CHECK}/20-console-shell-notice-app.png`, fullPage: true });
    });

    test('captures 21-console-account-menu', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(MOCKUP);
        await settled(page);
        await page.locator('[id="1a"] .dv-card').screenshot({
            path: `${VISUAL_CHECK}/21-console-account-menu-mockup.png`,
        });

        await openConsole(page);
        await trigger(page).click();
        await expect(menu(page)).toBeVisible();
        await settled(page);
        await page.getByRole('complementary', { name: 'Sidebar' }).screenshot({
            path: `${VISUAL_CHECK}/21-console-account-menu-app.png`,
        });
    });

    test('captures 19-console-shell', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(MOCKUP);
        await settled(page);
        await page.locator('[id="1e"] .dv-card').screenshot({
            path: `${VISUAL_CHECK}/19-console-shell-mockup.png`,
        });

        await openConsole(page);
        await settled(page);
        await page.screenshot({ path: `${VISUAL_CHECK}/19-console-shell-app.png`, fullPage: true });
    });
});

async function openConsoleWithUnnamedTenant(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width: 1280, height: 900 });

    await page.route(
        '**/admin',
        async (route) => {
            const response = await route.fetch();
            const html = await response.text();
            const encoded = html.match(/data-page="([^"]*)"/)?.[1];

            if (encoded === undefined) {
                await route.fulfill({ response, body: html });

                return;
            }

            const payload = JSON.parse(decode(encoded));

            payload.props.tenants = [
                ...payload.props.tenants,
                {
                    ...payload.props.tenants[0],
                    id: 999,
                    name: '',
                    slug: 'business-9',
                    owner_name: null,
                    booking_url: 'http://localhost:8000/book/business-9',
                },
            ];

            await route.fulfill({ response, body: html.replace(encoded, encode(JSON.stringify(payload))) });
        },
        { times: 1 },
    );

    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: 'Tenants' })).toBeVisible();
}

const unnamedRow = (page: Page) => page.getByRole('row').filter({ hasText: 'business-9' }).first();

test.describe('a tenant that has not been named yet', () => {
    test('says so rather than passing its booking URL off as the salon name', async ({ page }) => {
        await openConsoleWithUnnamedTenant(page);

        const label = unnamedRow(page).getByText('Unnamed salon', { exact: true });

        await expect(label).toBeVisible();
        await expect(label).toHaveCSS('font-style', 'italic');
        await expect(unnamedRow(page).locator('.font-mono')).toContainText('business-9');
    });

    test('leads the row with a neutral mark rather than a bare question mark', async ({ page }) => {
        await openConsoleWithUnnamedTenant(page);

        await expect(unnamedRow(page).getByText('?', { exact: true })).toHaveCount(0);
        await expect(unnamedRow(page).locator('svg').first()).toBeVisible();
    });

    test('is still findable by what it is called in the URL', async ({ page }) => {
        await openConsoleWithUnnamedTenant(page);

        await page.getByRole('textbox', { name: 'Search tenants' }).fill('unnamed');

        await expect(unnamedRow(page)).toBeVisible();
    });
});

test.describe('the breadcrumb', () => {
    const crumb = (page: Page) => page.getByTestId('console-breadcrumb');

    test('follows the screen you are on, not the one the rail lists first', async ({ page }) => {
        await openConsole(page);

        await expect(crumb(page)).toContainText('Tenants');

        await page.getByRole('link', { name: /^Failures/ }).click();
        await expect(page.getByRole('heading', { name: 'Failures' })).toBeVisible();
        await expect(crumb(page)).toContainText('Platform');
        await expect(crumb(page)).toContainText('Failures');
        await expect(crumb(page)).not.toContainText('Tenants');

        await page.getByRole('link', { name: 'Verticals' }).click();
        await expect(page.getByRole('heading', { name: 'Verticals' })).toBeVisible();
        await expect(crumb(page)).toContainText('Verticals');
        await expect(crumb(page)).not.toContainText('Failures');
    });

    test('marks exactly one rail link as the current page', async ({ page }) => {
        await page.clock.setFixedTime(new Date(FROZEN_NOW));
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/admin/failures');
        await expect(page.getByRole('heading', { name: 'Failures' })).toBeVisible();

        const rail = page.getByRole('complementary', { name: 'Sidebar' });

        await expect(rail.locator('[aria-current="page"]')).toHaveCount(1);
        await expect(rail.locator('[aria-current="page"]')).toContainText('Failures');
    });
});

test.describe('regression check artefacts', () => {
    test('captures 22-console-tenants-unnamed', async ({ page }) => {
        await openConsoleWithUnnamedTenant(page);
        await settled(page);
        await page.screenshot({ path: `${VISUAL_CHECK}/22-console-tenants-unnamed-app.png` });
    });

    test('captures 23-console-failures-crumb', async ({ page }) => {
        await page.clock.setFixedTime(new Date(FROZEN_NOW));
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/admin/failures');
        await expect(page.getByRole('heading', { name: 'Failures' })).toBeVisible();
        await settled(page);
        await page.screenshot({ path: `${VISUAL_CHECK}/23-console-failures-crumb-app.png` });
    });

    test('captures 24-console-sidebar-identity', async ({ page }) => {
        await openConsole(page);
        await trigger(page).click();
        await expect(menu(page)).toBeVisible();
        await settled(page);
        await page.getByRole('complementary', { name: 'Sidebar' }).screenshot({
            path: `${VISUAL_CHECK}/24-console-sidebar-identity-app.png`,
        });
    });
});
